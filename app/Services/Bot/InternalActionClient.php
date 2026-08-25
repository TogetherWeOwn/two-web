<?php

namespace App\Services\Bot;

use App\Jobs\CallInternalAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The website's half of the internal actions endpoint (TOG-470).
 *
 * One signed HTTP attempt per call, and no retry policy: deciding *whether* to
 * try again is {@see CallInternalAction}'s job, because a retry needs
 * a durable place to wait and this class does not have one. Keeping the split
 * here means the retry rules are tested without a network and the signing is
 * tested without a queue.
 *
 * The trust model, from `docs/INTERNAL_ACTIONS.md` v0.3: the website never
 * holds the Discord bot token. It names an action from a fixed allowlist and
 * the bot decides whether to do it. An attacker with this shared secret can do
 * exactly the things on that allowlist and nothing else.
 */
class InternalActionClient
{
    /**
     * Send one signed attempt.
     *
     * A fresh nonce and timestamp are minted here, on every call, which is what
     * makes this safe to call again for a retry: §1 requires the nonce to be new
     * every time and the idempotency key to stay the same, so the key is a
     * parameter and the nonce is not.
     *
     * @param  string|null  $idempotencyKey  required for the *needs key* actions of §3
     *
     * @throws InternalActionMisconfigured when the URL, key id or secret is absent
     * @throws \InvalidArgumentException when a needs-key action arrives without a key
     */
    public function send(InternalAction $action, ?string $idempotencyKey = null): InternalActionResult
    {
        $url = $this->endpoint();
        $keyId = $this->config('key_id');
        $secret = $this->config('secret');

        // Caught here rather than at the bot: §3 is explicit that a needs-key
        // action without a key is a `malformed`, not a best-effort attempt. That
        // would be a wasted round trip and a misleading error code.
        if ($action->needsIdempotencyKey() && ($idempotencyKey === null || $idempotencyKey === '')) {
            throw new \InvalidArgumentException(
                "{$action->name} requires an Idempotency-Key (docs/INTERNAL_ACTIONS.md §3, 'needs key')."
            );
        }

        // Encoded exactly once. `$raw` is what gets hashed into the canonical
        // string and `$raw` is what goes on the wire — re-serialising the
        // payload to build the request body is the §1 mistake that presents as
        // an intermittent 401.
        $raw = $action->rawBody();
        $nonce = InternalActionSigner::nonce();
        $timestamp = (string) now()->getTimestamp();

        $headers = [
            'X-TWO-Key-Id' => $keyId,
            'X-TWO-Timestamp' => $timestamp,
            'X-TWO-Nonce' => $nonce,
            'X-TWO-Signature' => InternalActionSigner::sign($secret, $timestamp, $nonce, $raw),
        ];

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders($headers)
                ->withBody($raw, 'application/json')
                ->timeout($this->timeout())
                ->post($url);
        } catch (ConnectionException $e) {
            // No HTTP response at all. Not an error code from §2's table, so it
            // gets our own, and it is retryable for the reason set out on
            // InternalActionResult::transportFailure().
            $result = InternalActionResult::transportFailure($e->getMessage());
            $this->log($action, $result, $keyId, $startedAt);

            return $result;
        }

        $result = $this->interpret($response);
        $this->log($action, $result, $keyId, $startedAt);

        return $result;
    }

    /**
     * Throw unless the URL, key id and secret are all present.
     *
     * Exists for callers that need to know *before* they start — the smoke
     * command, which has to answer with a distinct "misconfigured" exit code
     * and cannot get it from {@see self::send()}: the job deliberately absorbs
     * that exception into `fail()` so a worker does not retry a blank secret
     * four more times.
     *
     * @throws InternalActionMisconfigured
     */
    public function assertConfigured(): void
    {
        foreach (['url', 'key_id', 'secret'] as $key) {
            $this->config($key);
        }
    }

    /**
     * Turn the response into §2's envelope.
     */
    private function interpret(Response $response): InternalActionResult
    {
        $body = $response->json();

        if (! is_array($body)) {
            // A response from something that is not the bot — a proxy error page,
            // most likely. There is no `error.retryable` to obey, so fall back to
            // the status.
            return InternalActionResult::failure(
                status: $response->status(),
                code: 'unparseable_response',
                message: 'Response body was not JSON.',
                retryable: $this->retryableByStatus($response->status()),
                requestId: null,
                retryAfter: $this->retryAfter($response),
            );
        }

        $requestId = is_string($body['request_id'] ?? null) ? $body['request_id'] : null;

        if (($body['ok'] ?? false) === true) {
            /** @var array<string, mixed> $result */
            $result = is_array($body['result'] ?? null) ? $body['result'] : [];

            // §2: `Idempotent-Replay: true` on a 200 means the operation happened
            // on an earlier attempt. The `result` body is byte-identical either
            // way, so this is carried for logging rather than for control flow —
            // a caller that ignores it is still correct.
            return InternalActionResult::success(
                status: $response->status(),
                result: $result,
                requestId: $requestId,
                replayed: strtolower((string) $response->header('Idempotent-Replay')) === 'true',
            );
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        // The whole point of §2's table: branch on the boolean, never on the
        // prose. `409 in_progress` is retryable and `409 replayed` is not, and
        // no amount of reading the message tells you that as reliably as the
        // field does. The status is only consulted when the field is absent.
        $retryable = array_key_exists('retryable', $error)
            ? (bool) $error['retryable']
            : $this->retryableByStatus($response->status());

        return InternalActionResult::failure(
            status: $response->status(),
            code: is_string($error['code'] ?? null) ? $error['code'] : null,
            message: is_string($error['message'] ?? null) ? $error['message'] : null,
            retryable: $retryable,
            requestId: $requestId,
            retryAfter: $this->retryAfter($response),
        );
    }

    /**
     * Only used when the body carried no `retryable` field. Deliberately
     * conservative: anything we do not recognise is treated as final, because a
     * retry loop against an endpoint that is answering clearly is worse than
     * one failed job.
     */
    private function retryableByStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * §1: over the rate limit returns 429 with `Retry-After` in seconds. Honour
     * it — the limit exists so a loop bug on the website is an annoying
     * afternoon rather than our bot getting flagged by Discord.
     */
    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if ($header === '' || ! is_numeric($header)) {
            return null;
        }

        return max(0, (int) $header);
    }

    /**
     * §4's rules apply to us too: never the secret, never the signature, never
     * the announcement body. Action, outcome, code and `request_id` only — which
     * is everything needed to join this line to the bot's `internal_action_log`
     * row and no more.
     */
    private function log(InternalAction $action, InternalActionResult $result, string $keyId, float $startedAt): void
    {
        $context = [
            'action' => $action->name,
            'key_id' => $keyId,
            'status' => $result->status,
            'code' => $result->errorCode,
            'retryable' => $result->retryable,
            'replay' => $result->replayed,
            'request_id' => $result->requestId,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];

        if ($result->ok) {
            Log::info('internal action succeeded', $context);
        } elseif ($result->retryable) {
            Log::warning('internal action failed, retryable', $context);
        } else {
            Log::error('internal action failed, not retryable', $context);
        }
    }

    /**
     * `BOT_ENDPOINT_URL` is a base address, and the path is appended from the
     * constant that is also signed — so the string in the canonical block and
     * the string in the request line cannot drift apart.
     *
     * A URL that already ends in the path is accepted as-is, because the bot's
     * own documentation and the acceptance harness both quote the full endpoint
     * and somebody will paste that into the .env.
     */
    private function endpoint(): string
    {
        $base = rtrim($this->config('url'), '/');

        return str_ends_with($base, InternalActionSigner::PATH)
            ? $base
            : $base.InternalActionSigner::PATH;
    }

    private function timeout(): int
    {
        $timeout = config('services.bot.timeout');

        return is_numeric($timeout) ? (int) $timeout : 5;
    }

    /**
     * @throws InternalActionMisconfigured
     */
    private function config(string $key): string
    {
        $value = config("services.bot.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw InternalActionMisconfigured::missing($key);
        }

        return $value;
    }
}
