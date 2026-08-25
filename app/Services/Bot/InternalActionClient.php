<?php

namespace App\Services\Bot;

use App\Services\Bot\Exceptions\BotNotConfiguredException;
use App\Services\Bot\Exceptions\BotTransportException;
use App\Services\Bot\Exceptions\InvalidActionRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * The one place in this codebase that knows how to talk to the bot.
 *
 * The website never holds the Discord bot token. When it needs something to
 * happen in the TWO server it calls a single endpoint on the bot, over the
 * private network, HMAC-signed, naming an action from a fixed allowlist, and the
 * bot decides whether to do it. That is the whole trust model, and it only holds
 * while there is exactly one class that constructs the request — a second one
 * would be a second place to get the nonce rules wrong.
 *
 * Wire format: two-bot `docs/INTERNAL_ACTIONS.md`, v0.3.
 *
 * ## The nonce and the idempotency key are different things
 *
 * They are the reason this class is worth having, because using one value for
 * both passes every happy-path test and then breaks on the first retry.
 *
 * - The **nonce** proves this HTTP attempt is not a recording of an earlier one.
 *   It is minted here, per attempt, and is deliberately not a parameter — there
 *   is no way for a caller to hold one still. Reuse it and the bot answers
 *   `replayed`, a 409 that never becomes anything else.
 * - The **idempotency key** identifies the *operation*, and is a required
 *   parameter for the opposite reason: it has to survive across attempts, which
 *   means it has to be stored by whoever owns the retry (TOG-52c). Mint a fresh
 *   one per attempt and the retry creates a *second* Discord event.
 *
 * ## What comes back
 *
 * Three outcomes, and the type says which:
 *
 * - `EventUpsertResult` — it worked.
 * - `InternalActionFailure` — the bot answered no. Branch on `retryable`, never
 *   on the status: `in_progress` and `replayed` are both 409 and disagree.
 * - a thrown exception — we could not ask (`BotNotConfiguredException`, terminal)
 *   or got back nothing usable (`BotTransportException`, worth another attempt).
 *
 * ## What is not here
 *
 * Retrying, backoff and scheduling. This class makes one attempt and reports
 * what happened; the queued job owns the policy (TOG-52c). Anything that retried
 * in here would be a second, invisible retry policy underneath the visible one.
 */
final readonly class InternalActionClient
{
    public function __construct(
        private ?string $url,
        private ?string $secret,
        private ?string $keyId,
        private int $timeoutSeconds,
    ) {}

    /**
     * A key for one logical operation.
     *
     * Store it with the operation and reuse it for every attempt at that
     * operation. Calling this again asks for a *different* operation.
     */
    public static function newIdempotencyKey(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Create or update the Discord scheduled event for one `event_key`.
     *
     * @param  string  $idempotencyKey  From newIdempotencyKey(), stored against
     *                                  the operation and identical on every
     *                                  attempt at it.
     *
     * @throws BotNotConfiguredException when there is no url, secret or key id
     * @throws BotTransportException when the bot did not answer the contract
     * @throws InvalidActionRequestException when the idempotency key is not a UUID
     */
    public function upsertEvent(EventUpsert $event, string $idempotencyKey): EventUpsertResult|InternalActionFailure
    {
        $answer = $this->send($event->toPayload(), $idempotencyKey);

        if ($answer instanceof InternalActionFailure) {
            return $answer;
        }

        [$body, $status, $replayed] = $answer;

        $result = $body['result'] ?? null;

        if (! is_array($result)) {
            throw BotTransportException::unreadable('a success with no result object', $status);
        }

        $outcome = EventUpsertOutcome::tryFrom(is_string($result['outcome'] ?? null) ? $result['outcome'] : '');

        if ($outcome === null) {
            throw BotTransportException::unreadable('an event.upsert outcome this release does not know', $status);
        }

        // The event id is the point of the call: without it the site cannot link
        // a member to the event it just scheduled, so a success without one is
        // not a success we can store.
        if (! isset($result['event_id']) || ! is_scalar($result['event_id'])) {
            throw BotTransportException::unreadable('an event.upsert success with no event_id', $status);
        }

        return new EventUpsertResult(
            requestId: $this->requestId($body),
            outcome: $outcome,
            discordEventId: (string) $result['event_id'],
            replayed: $replayed,
        );
    }

    /**
     * One signed attempt.
     *
     * @param  array<string, mixed>  $payload
     * @return array{array<string, mixed>, int, bool}|InternalActionFailure the
     *                                                                      decoded success envelope, its status and whether it was replayed
     *                                                                      from the bot's idempotency store — or the bot's typed refusal
     *
     * @throws BotNotConfiguredException
     * @throws BotTransportException
     * @throws InvalidActionRequestException
     */
    private function send(array $payload, string $idempotencyKey): array|InternalActionFailure
    {
        $url = $this->endpoint();

        if (! Str::isUuid($idempotencyKey)) {
            throw new InvalidActionRequestException(
                'An Idempotency-Key must be a UUID; the bot answers a malformed one with a non-retryable `malformed`.'
            );
        }

        // Build the bytes once and send those exact bytes. Http::post($url,
        // $array) re-encodes, and the re-encoded body is not guaranteed to hash
        // to what was signed — the doc's own warning, and it would present as an
        // intermittent, unexplained `unauthorized`.
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidActionRequestException('The action payload could not be encoded as JSON.', 0, $e);
        }

        $timestamp = Carbon::now()->getTimestamp();

        // 128 bits, fresh for this attempt and this attempt only.
        $nonce = bin2hex(random_bytes(16));

        $headers = (new InternalActionSigner((string) $this->keyId, (string) $this->secret))
            ->headers($json, $timestamp, $nonce);

        $headers['Idempotency-Key'] = $idempotencyKey;

        $startedAt = Carbon::now();

        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeoutSeconds)
                ->withBody($json, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            $this->logUndelivered($payload, $idempotencyKey, $e);

            throw BotTransportException::unreachable($url, $e);
        }

        return $this->interpret($response, $payload, $idempotencyKey, $startedAt);
    }

    /**
     * The v0.3 envelope, turned into one of our two answers.
     *
     * @param  array<string, mixed>  $payload
     * @return array{array<string, mixed>, int, bool}|InternalActionFailure
     *
     * @throws BotTransportException
     */
    private function interpret(Response $response, array $payload, string $idempotencyKey, Carbon $startedAt): array|InternalActionFailure
    {
        $status = $response->status();
        $body = $response->json();

        if (! is_array($body) || ! array_key_exists('ok', $body)) {
            throw BotTransportException::unreadable('no `ok` field', $status);
        }

        $requestId = $this->requestId($body);
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : null;
        $duration = (int) $startedAt->diffInMilliseconds(Carbon::now(), absolute: true);

        if ($body['ok'] === true) {
            $replayed = $response->header('Idempotent-Replay') === 'true';

            Log::info('Bot internal action succeeded.', [
                'request_id' => $requestId,
                'action' => $action,
                'idempotency_key' => $idempotencyKey,
                'status' => $status,
                'replayed' => $replayed,
                'duration_ms' => $duration,
            ]);

            return [$body, $status, $replayed];
        }

        $error = $body['error'] ?? null;

        if (! is_array($error) || ! is_string($error['code'] ?? null)) {
            throw BotTransportException::unreadable('a failure with no error code', $status);
        }

        /** @var string $code */
        $code = $error['code'];

        $failure = new InternalActionFailure(
            code: $code,
            // Authoritative from the wire where the bot sent one. Otherwise the
            // published table for a code we know, and false for one we do not:
            // never retry against an answer we cannot reason about.
            retryable: is_bool($error['retryable'] ?? null)
                ? $error['retryable']
                : (InternalActionErrorCode::tryFrom($code)?->isRetryable() ?? false),
            message: is_string($error['message'] ?? null) ? $error['message'] : '',
            requestId: $requestId,
            status: $status,
            retryAfterSeconds: $status === 429 ? $this->retryAfter($response) : null,
        );

        Log::warning('Bot internal action refused.', [
            'request_id' => $failure->requestId,
            'action' => $action,
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'code' => $failure->code,
            'retryable' => $failure->retryable,
            'retry_after' => $failure->retryAfterSeconds,
            'duration_ms' => $duration,
        ]);

        return $failure;
    }

    /**
     * Retry-After, in seconds.
     *
     * The doc says seconds. A value that is not a count of seconds is not worth
     * half-parsing into a nonsense backoff — the caller's own default is a better
     * answer than an HTTP-date misread as a duration.
     */
    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return ctype_digit($header) ? (int) $header : null;
    }

    /** @param  array<string, mixed>  $body */
    private function requestId(array $body): string
    {
        return is_string($body['request_id'] ?? null) ? $body['request_id'] : '';
    }

    /**
     * The signed URL, once we know we are configured to sign at all.
     *
     * Checked before anything is built or sent. An empty secret would otherwise
     * produce a perfectly well-formed signature over an empty key, which the bot
     * rejects as `unauthorized` — indistinguishable, deliberately, from a forged
     * request, and so the hardest thing there is to diagnose from the far end.
     *
     * @throws BotNotConfiguredException
     */
    private function endpoint(): string
    {
        if (($this->url ?? '') === '') {
            throw BotNotConfiguredException::missing('BOT_ENDPOINT_URL');
        }

        if (($this->secret ?? '') === '') {
            throw BotNotConfiguredException::missing('BOT_SHARED_SECRET');
        }

        if (($this->keyId ?? '') === '') {
            throw BotNotConfiguredException::missing('BOT_KEY_ID');
        }

        return rtrim((string) $this->url, '/').InternalActionSigner::PATH;
    }

    /** @param  array<string, mixed>  $payload */
    private function logUndelivered(array $payload, string $idempotencyKey, Throwable $e): void
    {
        // No request_id: the bot never saw this one. Logged anyway, so that a run
        // of these reads as a network or timeout problem rather than as silence.
        Log::warning('Bot internal action could not be delivered.', [
            'action' => is_string($payload['action'] ?? null) ? $payload['action'] : null,
            'idempotency_key' => $idempotencyKey,
            'exception' => $e->getMessage(),
        ]);
    }
}
