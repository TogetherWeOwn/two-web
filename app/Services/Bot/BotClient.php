<?php

namespace App\Services\Bot;

use App\Enums\JoinOutcome;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * The website's side of the bot's internal actions endpoint
 * (two-bot: docs/INTERNAL_ACTIONS.md, wire format v0.2).
 *
 * The website never holds the Discord bot token. When it needs something to
 * happen inside the TWO server it asks the bot, over the private network, with
 * an HMAC signature, naming an action from the bot's fixed allowlist. That
 * split is the whole trust model: our shared secret buys exactly the actions on
 * that allowlist and nothing else, so a compromise of this site cannot kick,
 * ban, read the guild, or change a permission — there is no verb for it.
 *
 * Only `guild.add_member` is implemented here, because it is the only action
 * the website currently needs. Adding another means reading §3 first: the ones
 * marked *needs key* require an `Idempotency-Key` and this class deliberately
 * sends none.
 */
class BotClient
{
    /**
     * Part of the signed canonical string, so it is not merely the path we post
     * to — it is a value both sides hash. It cannot be made configurable
     * without changing the signature on both sides at once.
     */
    public const ACTIONS_PATH = '/internal/actions';

    /**
     * Add somebody to the TWO server with their own OAuth token.
     *
     * `$accessToken` is a member's live credential. It is an argument, a request
     * body, and nothing else: it is never returned, never put in a log line,
     * never written to the session, and never queued. That last one is the
     * reason this call is synchronous rather than a job — a queued job would
     * write the token into the `jobs` table in plaintext, into `failed_jobs` on
     * error, and into every database backup taken afterwards
     * (docs/INTERNAL_ACTIONS.md §5). tests/Feature/Join/AccessTokenIsNeverLoggedTest.php
     * holds the logging half of that promise.
     */
    public function addMember(string $discordId, string $accessToken): AddMemberResult
    {
        // Nothing is deployed yet, and locally the secret is blank. Fail closed
        // and make no HTTP call rather than posting an unsigned request into the
        // void — the caller shows an invite link either way, and the log line is
        // the difference between "misconfigured" and "the bot is down".
        if (! $this->configured()) {
            Log::error('guild.add_member was called but services.bot is not configured.');

            return AddMemberResult::failed('not_configured', retryable: false);
        }

        try {
            $raw = (string) json_encode([
                'action' => 'guild.add_member',
                'discord_id' => $discordId,
                'access_token' => $accessToken,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A token that is not valid UTF-8. Nothing to say about it that does
            // not risk quoting it, so the message carries no detail at all.
            Log::error('guild.add_member payload could not be encoded.');

            return AddMemberResult::failed('malformed_request', retryable: false);
        }

        try {
            // withBody, not post($url, $array): the signature covers a hash of the
            // bytes we send, so the body must not be re-serialised between signing
            // and sending. Laravel sends `pendingBody` verbatim.
            $response = Http::withBody($raw, 'application/json')
                ->withHeaders($this->signedHeaders($raw))
                // Both halves of the budget, because a bot host that drops packets
                // would otherwise sit in Laravel's 10s default connect timeout and
                // blow the 2s a member is standing there waiting for.
                ->connectTimeout($this->addMemberTimeout())
                ->timeout($this->addMemberTimeout())
                // No retry(). §5: a member is waiting, the bot does not retry
                // either, and a second attempt would need a fresh nonce and spend
                // a budget we do not have. We fail fast and fall back.
                ->post($this->url());
        } catch (Throwable $e) {
            // The bot is not listening, or did not answer inside the budget.
            // getMessage() on a Guzzle failure can quote the request, so it goes
            // through the redactor rather than straight into the log.
            Log::warning('The bot did not answer guild.add_member.', [
                'exception' => $this->redact($e->getMessage(), $accessToken),
            ]);

            return AddMemberResult::failed('unreachable', retryable: true);
        }

        // A body that is not JSON at all decodes to null and casts to [], which
        // falls through to `malformed_response` below rather than throwing.
        return $this->interpret($response->status(), (array) $response->json());
    }

    /**
     * True when this site has everything it needs to call the bot at all.
     *
     * The join page asks first, so that a visitor is never sent through a Discord
     * consent screen we cannot honour. Approving `guilds.join` and then being
     * handed an invite link anyway is a worse experience than being handed the
     * invite link in the first place.
     */
    public function configured(): bool
    {
        // The base URL, not url(): that one always has the actions path glued on
        // and so is never empty, which would make this always true.
        return $this->baseUrl() !== '' && $this->secret() !== '' && $this->keyId() !== '';
    }

    /**
     * The canonical string and its HMAC, from docs/INTERNAL_ACTIONS.md §1:
     *
     *   canonical = "POST\n/internal/actions\n{timestamp}\n{nonce}\n{sha256_hex(raw_body)}"
     *   signature = "sha256=" + hex(hmac_sha256(shared_secret, canonical))
     *
     * Static and pure so it can be pinned against a vector generated by the
     * bot's own signer — see tests/Unit/BotSignatureTest.php. Two independent
     * implementations agreeing on a fixed input is the only way to catch an
     * encoding disagreement before it presents as a pile of unexplained 401s.
     */
    public static function signature(string $secret, string $timestamp, string $nonce, string $raw): string
    {
        $canonical = implode("\n", [
            'POST',
            self::ACTIONS_PATH,
            $timestamp,
            $nonce,
            hash('sha256', $raw),
        ]);

        return 'sha256='.hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $raw): array
    {
        // Unix seconds, and the bot rejects anything more than 120s from its own
        // clock. Both hosts must run NTP; drift presents as intermittent 401s
        // with `stale_request`, which is why that code is worth reading in a log.
        $timestamp = (string) time();

        // 128 bits, fresh on every attempt including retries — the nonce proves
        // this HTTP attempt is not a recording of an earlier one. It is NOT an
        // idempotency key and must never be reused as one (§1).
        $nonce = bin2hex(random_bytes(16));

        return [
            'X-TWO-Key-Id' => $this->keyId(),
            'X-TWO-Timestamp' => $timestamp,
            'X-TWO-Nonce' => $nonce,
            'X-TWO-Signature' => self::signature($this->secret(), $timestamp, $nonce, $raw),
        ];
        // No Idempotency-Key: guild.add_member is naturally idempotent because
        // Discord answers 204 when the person is already in (§3).
    }

    /**
     * Turn the bot's envelope into a result. Every branch returns something the
     * caller can show a member; none of them throw.
     *
     * @param  array<mixed>  $body
     */
    private function interpret(int $status, array $body): AddMemberResult
    {
        $requestId = $this->stringOrNull($body['request_id'] ?? null);

        if ($status >= 200 && $status < 300 && ($body['ok'] ?? null) === true) {
            $result = $body['result'] ?? null;
            $raw = is_array($result) ? $this->stringOrNull($result['outcome'] ?? null) : null;
            $outcome = $raw === null ? null : JoinOutcome::tryFrom($raw);

            if ($outcome === null) {
                // The bot grew an outcome we have no sentence for. Treat it as a
                // failure so the member gets the invite link instead of a blank
                // page, and shout, because this is a contract drift.
                Log::error('The bot reported a guild.add_member outcome this site does not know.', [
                    'outcome' => $raw,
                    'request_id' => $requestId,
                ]);

                return AddMemberResult::failed('unknown_outcome', retryable: false, requestId: $requestId);
            }

            Log::info('guild.add_member succeeded.', [
                'outcome' => $outcome->value,
                'request_id' => $requestId,
            ]);

            return AddMemberResult::succeeded($outcome, $requestId);
        }

        $error = $body['error'] ?? null;
        $code = is_array($error) ? $this->stringOrNull($error['code'] ?? null) : null;
        $retryable = is_array($error) && ($error['retryable'] ?? null) === true;

        if ($code === null) {
            // A 2xx with `ok: false`, or a body that is not the envelope at all.
            // Usually means something that is not the bot answered on that port.
            Log::warning('The bot returned something that is not the error envelope.', [
                'status' => $status,
            ]);

            return AddMemberResult::failed('malformed_response', retryable: false, requestId: $requestId);
        }

        // `action_not_allowed` is the expected answer until the CEO sets
        // TWO_INTERNAL_ALLOW_ADD_MEMBER=1 on the bot. It is a configuration
        // state, not a fault, and it degrades to the invite link like any other.
        Log::warning('The bot refused guild.add_member.', [
            'status' => $status,
            'code' => $code,
            'retryable' => $retryable,
            'request_id' => $requestId,
            // Never the message: it can quote Discord, and nothing branches on it.
        ]);

        return AddMemberResult::failed($code, $retryable, $requestId);
    }

    /**
     * Strip a member's credential out of text we are about to log.
     *
     * Belt and braces over "we do not log the token": an exception message is
     * written by somebody else's library and can quote whatever it likes. The
     * shared secret goes too — it is never in a message today, and this is the
     * cheapest way to keep that true.
     */
    private function redact(string $message, string $accessToken): string
    {
        foreach ([$accessToken, $this->secret()] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return $message;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.bot.url'), '/');
    }

    private function url(): string
    {
        return $this->baseUrl().self::ACTIONS_PATH;
    }

    private function secret(): string
    {
        return (string) config('services.bot.secret');
    }

    private function keyId(): string
    {
        return (string) config('services.bot.key_id');
    }

    private function addMemberTimeout(): int
    {
        return (int) config('services.bot.add_member_timeout');
    }
}
