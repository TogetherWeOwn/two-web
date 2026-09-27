<?php

namespace App\Services\Bot;

use App\Enums\JoinOutcome;
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
 * Wire format: two-bot `docs/INTERNAL_ACTIONS.md`, v0.4. v0.4 changed neither
 * the wire format nor the allowlist, so v0.3's rules below still stand verbatim.
 *
 * ## The four live actions
 *
 * One public method each, and the signature carries §3's *needs key* column
 * rather than a runtime flag:
 *
 * - `assignRole()` and `addMember()` take **no** idempotency key. Both actions
 *   are naturally idempotent at Discord.
 * - `postAnnouncement()` and `upsertEvent()` **require** one. A repeat without a
 *   key posts a second message or creates a second event.
 *
 * `addMember()` is the deliberate synchronous exception to the queued rule. It
 * receives a live member OAuth token which must never enter a queue payload,
 * failed-jobs row or database backup, so the browser request owns its one short
 * attempt and falls back to the plain invite when the bot does not answer.
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
 * - a result object — `RoleAssignResult`, `AnnouncementResult` or
 *   `EventUpsertResult` — it worked.
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
     * Throw unless the url, secret and key id are all present.
     *
     * Exists for callers that need to know *before* they start — the
     * `bot:internal-action-smoke` command, which owes its caller a distinct
     * "misconfigured" exit code and cannot get one from a send: the queued job
     * deliberately absorbs this exception into `fail()`, so that a worker does
     * not rediscover the same blank secret four more times, and `fail()` is a
     * no-op when the job is run inline.
     *
     * Nothing else should call this. An action that checks first and then sends
     * has two chances to disagree about what "configured" means; every send
     * checks for itself.
     *
     * @throws BotNotConfiguredException
     */
    public function assertConfigured(): void
    {
        $this->endpoint();
    }

    /**
     * Give one member one role, by key.
     *
     * No idempotency key, by contract (§3): assigning a role somebody already
     * holds is a no-op at Discord, so the bot needs no stored state to be safe
     * and this action does not send one. A repeat comes back `already_held`,
     * which is a success.
     *
     * @throws BotNotConfiguredException when there is no url, secret or key id
     * @throws BotTransportException when the bot did not answer the contract
     */
    public function assignRole(RoleAssignment $assignment): RoleAssignResult|InternalActionFailure
    {
        $answer = $this->send($assignment->toPayload(), null);

        if ($answer instanceof InternalActionFailure) {
            return $answer;
        }

        [$body, $status] = $answer;

        $result = $this->resultObject($body, $status);

        $outcome = RoleAssignOutcome::tryFrom(is_string($result['outcome'] ?? null) ? $result['outcome'] : '');

        if ($outcome === null) {
            throw BotTransportException::unreadable('a role.assign outcome this release does not know', $status);
        }

        return new RoleAssignResult(
            requestId: $this->requestId($body),
            outcome: $outcome,
        );
    }

    /**
     * Add somebody to the TWO server with their one-use OAuth token.
     *
     * No idempotency key, no retry and no queue. The token exists only in this
     * stack frame and the signed request body; callers must not store it.
     *
     * @throws BotNotConfiguredException when there is no url, secret or key id
     * @throws BotTransportException when the bot did not answer the contract
     */
    public function addMember(string $discordId, string $accessToken): AddMemberResult
    {
        $answer = $this->send([
            'action' => 'guild.add_member',
            'discord_id' => $discordId,
            'access_token' => $accessToken,
        ], null, [$accessToken]);

        if ($answer instanceof InternalActionFailure) {
            return AddMemberResult::failed($answer);
        }

        [$body, $status] = $answer;

        $result = $this->resultObject($body, $status);
        $outcome = JoinOutcome::tryFrom(is_string($result['outcome'] ?? null) ? $result['outcome'] : '');

        if ($outcome === null) {
            throw BotTransportException::unreadable('a guild.add_member outcome this release does not know', $status);
        }

        return AddMemberResult::succeeded($outcome, $this->requestId($body));
    }

    /**
     * Post one announcement to a channel, by key.
     *
     * @param  string  $idempotencyKey  From newIdempotencyKey(), stored against
     *                                  the operation and identical on every
     *                                  attempt at it. A fresh key per attempt
     *                                  posts the announcement twice.
     *
     * @throws BotNotConfiguredException when there is no url, secret or key id
     * @throws BotTransportException when the bot did not answer the contract
     * @throws InvalidActionRequestException when the idempotency key is not a UUID
     */
    public function postAnnouncement(Announcement $announcement, string $idempotencyKey): AnnouncementResult|InternalActionFailure
    {
        $answer = $this->send($announcement->toPayload(), $idempotencyKey);

        if ($answer instanceof InternalActionFailure) {
            return $answer;
        }

        [$body, $status, $replayed] = $answer;

        $result = $this->resultObject($body, $status);

        // The message id is the point of the call: it is how a retry proves it
        // did not post a second time, and how the site links to what it posted.
        // §3 guarantees it comes back on a replay too, so a success without one
        // is not a success we can store.
        if (! isset($result['message_id']) || ! is_scalar($result['message_id'])) {
            throw BotTransportException::unreadable('an announcement.post success with no message_id', $status);
        }

        return new AnnouncementResult(
            requestId: $this->requestId($body),
            messageId: (string) $result['message_id'],
            replayed: $replayed,
        );
    }

    /**
     * Cancel the Discord scheduled event for one `event_key`.
     *
     * A distinct action from `upsertEvent`, not a flag on it. The bot moves the
     * event to CANCELED (Discord `status: 4`, never a delete) and only answers
     * `event.cancel` that way — sending a cancelled event through
     * `event.upsert` re-receives what looks like a live event, so the Discord
     * mirror stays live after the cancel. That is TOG-5863.
     *
     * The bot keys on its own `event_key -> discord_event_id` map: unknown keys
     * come back `action_not_allowed` without a Discord request, and the mapping
     * is retained on success so a delayed upsert cannot resurrect the event. A
     * replacement takes a new `event_key`.
     *
     * @param  string  $idempotencyKey  From newIdempotencyKey(), stored against
     *                                  the operation and identical on every
     *                                  attempt at it.
     *
     * @throws BotNotConfiguredException when there is no url, secret or key id
     * @throws BotTransportException when the bot did not answer the contract
     * @throws InvalidActionRequestException when the idempotency key is not a UUID
     */
    public function cancelEvent(EventCancel $event, string $idempotencyKey): EventCancelResult|InternalActionFailure
    {
        $answer = $this->send($event->toPayload(), $idempotencyKey);

        if ($answer instanceof InternalActionFailure) {
            return $answer;
        }

        [$body, $status, $replayed] = $answer;

        $result = $this->resultObject($body, $status);

        $outcome = EventCancelOutcome::tryFrom(is_string($result['outcome'] ?? null) ? $result['outcome'] : '');

        if ($outcome === null) {
            throw BotTransportException::unreadable('an event.cancel outcome this release does not know', $status);
        }

        // The event id is the proof the cancel landed on the mirror we meant:
        // a success without one cannot be matched to anything.
        if (! isset($result['event_id']) || ! is_scalar($result['event_id'])) {
            throw BotTransportException::unreadable('an event.cancel success with no event_id', $status);
        }

        return new EventCancelResult(
            requestId: $this->requestId($body),
            outcome: $outcome,
            discordEventId: (string) $result['event_id'],
            replayed: $replayed,
        );
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

        $result = $this->resultObject($body, $status);

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
     * The `result` object out of a success envelope.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws BotTransportException
     */
    private function resultObject(array $body, int $status): array
    {
        $result = $body['result'] ?? null;

        if (! is_array($result)) {
            throw BotTransportException::unreadable('a success with no result object', $status);
        }

        return $result;
    }

    /**
     * One signed attempt.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $redactions
     * @param  string|null  $idempotencyKey  null only for an action §3 marks
     *                                       *natural*, which sends no
     *                                       `Idempotency-Key` header at all.
     *                                       Which actions those are is decided
     *                                       by the public method that called
     *                                       this, not by the caller.
     * @return array{array<string, mixed>, int, bool}|InternalActionFailure the
     *                                                                      decoded success envelope, its status and whether it was replayed
     *                                                                      from the bot's idempotency store — or the bot's typed refusal
     *
     * @throws BotNotConfiguredException
     * @throws BotTransportException
     * @throws InvalidActionRequestException
     */
    private function send(array $payload, ?string $idempotencyKey, array $redactions = []): array|InternalActionFailure
    {
        $url = $this->endpoint();

        // Only when there is one to check. A *needs key* action can never reach
        // here with null — its public method takes a non-nullable string — so
        // this is not a hole in that rule, it is the natural-idempotency case.
        if ($idempotencyKey !== null && ! Str::isUuid($idempotencyKey)) {
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

        // Absent, not blank, for a natural-idempotency action. An empty header
        // is a value the bot has to interpret; not sending one is the contract.
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $startedAt = Carbon::now();

        try {
            $response = Http::withHeaders($headers)
                ->timeout($this->timeoutSeconds)
                ->withBody($json, 'application/json')
                ->post($url);
        } catch (ConnectionException $e) {
            $this->logUndelivered($payload, $idempotencyKey, $e, $redactions);

            throw BotTransportException::unreachable($url);
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
    private function interpret(Response $response, array $payload, ?string $idempotencyKey, Carbon $startedAt): array|InternalActionFailure
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $redactions
     */
    private function logUndelivered(array $payload, ?string $idempotencyKey, Throwable $e, array $redactions = []): void
    {
        $message = $e->getMessage();

        foreach ([...$redactions, (string) $this->secret] as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        // No request_id: the bot never saw this one. Logged anyway, so that a run
        // of these reads as a network or timeout problem rather than as silence.
        Log::warning('Bot internal action could not be delivered.', [
            'action' => is_string($payload['action'] ?? null) ? $payload['action'] : null,
            'idempotency_key' => $idempotencyKey,
            'exception' => $message,
        ]);
    }
}
