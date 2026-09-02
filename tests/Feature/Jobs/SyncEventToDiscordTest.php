<?php

use App\Enums\EventStatus;
use App\Jobs\SyncEventToDiscord;
use App\Models\Event;
use App\Models\Rsvp;
use App\Services\Bot\Exceptions\BotNotConfiguredException;
use App\Services\Bot\InternalActionClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Logger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/*
 * The queued write-back to Discord.
 *
 * The card's promise is that the site degrades and never white-screens: an RSVP is
 * committed on the site whatever the bot is doing, and reconciles when the bot comes
 * back. That makes this job the only place allowed to be slow or to fail, so what is
 * asserted here is the failure behaviour, not the happy path:
 *
 *   - a retryable refusal is released, not failed, and the row stays unsynced;
 *   - an unreachable bot is released too — the RSVP is already committed;
 *   - a terminal refusal fails *immediately* rather than burning the backoff;
 *   - the idempotency key survives a retry, because a fresh one per attempt is how
 *     you get two Discord events out of one operation.
 */

const JOB_BOT_ENDPOINT = 'http://bot.internal:3001/internal/actions';

beforeEach(function () {
    config()->set('services.bot.url', 'http://bot.internal:3001');
    config()->set('services.bot.secret', 'two-web-test-secret-at-least-32-characters');
    config()->set('services.bot.key_id', 'web-test');
    config()->set('services.bot.timeout', 5);
});

/** A published event is the only kind Discord has ever been told about. */
function syncableEvent(array $attributes = []): Event
{
    return Event::factory()->create(array_merge([
        'status' => EventStatus::Published,
        'title' => 'Movie Night',
        'location' => 'Voice: General',
        'starts_at' => Carbon::parse('2026-09-01T18:00:00Z'),
        'ends_at' => Carbon::parse('2026-09-01T20:30:00Z'),
        'timezone' => 'Europe/London',
    ], $attributes));
}

function botOk(string $outcome = 'created', string $eventId = '1234567890'): array
{
    return ['ok' => true, 'result' => ['outcome' => $outcome, 'event_id' => $eventId], 'request_id' => '01JABCDEF0123456789'];
}

function botRefusal(string $code, bool $retryable): array
{
    return [
        'ok' => false,
        'error' => ['code' => $code, 'message' => 'the bot said no', 'retryable' => $retryable],
        'request_id' => '01JERROR0123456789',
    ];
}

// ---------------------------------------------------------------------------
// It works: the mirror is recorded and the answers are marked synced.
// ---------------------------------------------------------------------------

it('stores the discord event id and stamps the rsvps it just mirrored', function () {
    Http::fake([JOB_BOT_ENDPOINT => Http::response(botOk())]);

    $event = syncableEvent();
    $rsvp = Rsvp::factory()->create(['event_id' => $event->id, 'synced_to_discord_at' => null]);

    (new SyncEventToDiscord($event->event_key))->handle(app(InternalActionClient::class));

    expect($event->fresh()->discord_event_id)->toBe('1234567890')
        ->and($rsvp->fresh()->synced_to_discord_at)->not->toBeNull();
});

it('sends the location and never a channel key, because the key map starts empty', function () {
    Http::fake([JOB_BOT_ENDPOINT => Http::response(botOk())]);

    $event = syncableEvent();

    (new SyncEventToDiscord($event->event_key))->handle(app(InternalActionClient::class));

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return $body['action'] === 'event.upsert'
            && $body['location'] === 'Voice: General'
            && ! array_key_exists('channel_key', $body);
    });
});

it('sends the instant in Zulu, not the hosts local wall time', function () {
    Http::fake([JOB_BOT_ENDPOINT => Http::response(botOk())]);

    // 18:00Z is 19:00 in Europe/London on this date. The bot must be told the
    // instant; sending "19:00" with no zone is how an event lands an hour out.
    $event = syncableEvent();

    (new SyncEventToDiscord($event->event_key))->handle(app(InternalActionClient::class));

    Http::assertSent(function ($request) {
        $body = json_decode($request->body(), true);

        return $body['starts_at'] === '2026-09-01T18:00:00Z'
            && $body['ends_at'] === '2026-09-01T20:30:00Z';
    });
});

// ---------------------------------------------------------------------------
// The bot is down. The RSVP is already committed; the job waits and retries.
// ---------------------------------------------------------------------------

it('releases rather than failing when the bot cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $event = syncableEvent();
    $rsvp = Rsvp::factory()->create(['event_id' => $event->id, 'synced_to_discord_at' => null]);

    $job = (new SyncEventToDiscord($event->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    $job->assertReleased()->assertNotFailed();

    // The honest state: saved here, not yet in Discord. Not an error the member sees.
    expect($rsvp->fresh()->synced_to_discord_at)->toBeNull()
        ->and($event->fresh()->discord_event_id)->toBeNull();
});

it('releases on a retryable refusal from the bot', function () {
    Http::fake([JOB_BOT_ENDPOINT => Http::response(botRefusal('discord_unavailable', true), 502)]);

    $event = syncableEvent();

    $job = (new SyncEventToDiscord($event->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    $job->assertReleased()->assertNotFailed();
});

it('honours the bots retry-after on a rate limit instead of its own backoff', function () {
    Http::fake([JOB_BOT_ENDPOINT => Http::response(botRefusal('rate_limited', true), 429, ['Retry-After' => '90'])]);

    $event = syncableEvent();

    $job = (new SyncEventToDiscord($event->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    // The bot's number, not ours: it knows what the ceiling is and we do not.
    $job->assertReleased(delay: 90);
});

// ---------------------------------------------------------------------------
// Terminal failures. These must stop now — a retry cannot change the answer.
// ---------------------------------------------------------------------------

it('fails immediately on a non-retryable refusal rather than burning the backoff', function () {
    Http::fake([JOB_BOT_ENDPOINT => Http::response(botRefusal('action_not_allowed', false), 403)]);

    $event = syncableEvent();

    $job = (new SyncEventToDiscord($event->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    $job->assertFailed()->assertNotReleased();
});

it('fails when the bot is not configured, because no number of retries adds a secret', function () {
    config()->set('services.bot.secret', null);

    $event = syncableEvent();

    $job = (new SyncEventToDiscord($event->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    $job->assertFailed()->assertNotReleased();
})->throwsNoExceptions();

it('gives up quietly when the event was deleted while the job sat in the queue', function () {
    Http::fake();

    $job = (new SyncEventToDiscord('01JNOSUCHEVENT0000000000'))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    // Not a failure worth waking anybody for: the event is gone, so is the work.
    $job->assertDeleted()->assertNotFailed();
    Http::assertNothingSent();
});

it('does not tell discord about a draft, because a draft has never been published', function () {
    Http::fake();

    $event = syncableEvent(['status' => EventStatus::Draft]);

    (new SyncEventToDiscord($event->event_key))->handle(app(InternalActionClient::class));

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// The idempotency key. This is the one that produces duplicate Discord events.
// ---------------------------------------------------------------------------

it('reuses one idempotency key across every attempt at the same operation', function () {
    Http::fakeSequence()
        ->push(botRefusal('internal', true), 500)
        ->push(botOk());

    $event = syncableEvent();

    $job = (new SyncEventToDiscord($event->event_key))->withFakeQueueInteractions();

    // Attempt one fails and is released; attempt two is the *same* job instance
    // coming back off the queue, which is exactly how the worker replays it.
    $job->handle(app(InternalActionClient::class));
    $job->handle(app(InternalActionClient::class));

    $keys = [];
    Http::assertSent(function ($request) use (&$keys) {
        $keys[] = $request->header('Idempotency-Key')[0];

        return true;
    });

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($keys[1], 'A fresh key per attempt creates a second Discord event.');
});

it('logs the request id so a failure can be joined to the bots own logs', function () {
    Http::fake([JOB_BOT_ENDPOINT => Http::response(botRefusal('discord_rejected', false), 422)]);

    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('test', [$handler])));

    $event = syncableEvent();

    $job = (new SyncEventToDiscord($event->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    expect(collect($handler->getRecords())->contains(
        fn ($record) => str_contains(json_encode($record['context'] ?? []), '01JERROR0123456789')
    ))->toBeTrue();
});
