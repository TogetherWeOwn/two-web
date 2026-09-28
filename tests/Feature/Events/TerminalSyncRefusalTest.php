<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Jobs\SyncEventToDiscord;
use App\Livewire\RsvpButton;
use App\Models\AgentEventGrant;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\Bot\InternalActionClient;
use App\Services\EventService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
 * TOG-6990: a terminal bot refusal must settle, not re-dispatch forever.
 *
 * The bug: `SyncEventToDiscord` fails immediately on `retryable: false` ("no
 * amount of backoff adds one"), but the failure left the row shaped exactly
 * like a transient outage — published, `discord_event_id` null. The reconcile
 * pass matches that shape, so it re-dispatched the refused operation every
 * ten minutes forever: a `failed_jobs` row and spent bot budget per pass, for
 * an answer already given, while the member read "Syncing…" indefinitely.
 *
 * The fix is a stamp: terminal paths mark the row (`discord_sync_failed_at`
 * + code) so reconcile skips it, a genuinely new member or moderator change
 * clears the stamp and re-arms the next attempt, and the banner gets its
 * third state — saved here, refused over there — instead of "Syncing…"
 * forever.
 */

const REFUSAL_BOT_ENDPOINT = 'http://bot.internal:3001/internal/actions';

beforeEach(function () {
    config()->set('services.bot.url', 'http://bot.internal:3001');
    config()->set('services.bot.secret', 'two-web-test-secret-at-least-32-characters');
    config()->set('services.bot.key_id', 'web-test');
    config()->set('services.bot.timeout', 5);

    Carbon::setTestNow('2026-09-28T12:00:00Z');

    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->event = Event::factory()->create([
        'status' => EventStatus::Published,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function terminalRefusal(): array
{
    return [
        'ok' => false,
        'error' => ['code' => 'action_not_allowed', 'message' => 'the bot said no', 'retryable' => false],
        'request_id' => '01JTERMINAL0123456789',
    ];
}

/** A grant-scoped helper matching AgentEventIngressTest's shape. */
function refusedEventGrant(array $overrides = []): AgentEventGrant
{
    config()->set('agent-events.enabled', true);

    return AgentEventGrant::query()->create(array_merge([
        'agent_id' => 'c1f22b2f-d85f-41e1-9c16-9ca24ac06a11',
        'company_id' => 'ef993a7e-5ea7-445f-ba88-27a6a2690c3a',
        'guild_id' => '1545644954272137297',
        'verifier_hash' => AgentEventGrant::verifierFor('agent-test-credential-opaque-entropy-here'),
        'max_events' => 1,
    ], $overrides));
}

it('stamps a terminal refusal so the row stops looking like a transient outage', function () {
    Http::fake([REFUSAL_BOT_ENDPOINT => Http::response(terminalRefusal(), 403)]);

    $job = (new SyncEventToDiscord($this->event->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    $job->assertFailed()->assertNotReleased();

    // The verdict is now on the row: when it happened, and which answer.
    $fresh = $this->event->fresh();
    expect($fresh->discord_event_id)->toBeNull()
        ->and($fresh->discord_sync_failed_at)->not->toBeNull()
        ->and($fresh->discord_sync_failure_code)->toBe('action_not_allowed');
});

it('does not re-dispatch a terminally-refused event on the reconcile pass', function () {
    Queue::fake();

    // The terminally-refused row is the fixture itself: published, mirror
    // null — the exact shape that used to match every pass. The stamp is what
    // exempts it, and there is no other eligible row in this test.
    $this->event->forceFill([
        'discord_sync_failed_at' => now(),
        'discord_sync_failure_code' => 'action_not_allowed',
    ])->save();

    $this->artisan('events:reconcile')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('re-arms a terminally-refused event when the member answers again', function () {
    Queue::fake();

    $this->event->forceFill([
        'discord_sync_failed_at' => now(),
        'discord_sync_failure_code' => 'action_not_allowed',
    ])->save();

    // The new answer is a new operation the bot has not ruled on: the stamp
    // clears and the write-back goes out carrying it.
    app(EventService::class)->rsvp($this->event, $this->member, RsvpStatus::Going);

    $fresh = $this->event->fresh();
    expect($fresh->discord_sync_failed_at)->toBeNull()
        ->and($fresh->discord_sync_failure_code)->toBeNull();

    Queue::assertPushed(SyncEventToDiscord::class, fn ($job) => $job->eventKey === $this->event->event_key);
});

it('tells the member their answer counts instead of syncing forever', function () {
    $rsvp = Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
        'synced_to_discord_at' => null,
    ]);

    $this->event->forceFill([
        'discord_sync_failed_at' => now(),
        'discord_sync_failure_code' => 'action_not_allowed',
    ])->save();

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        // The answer stands: confirmation, not the error banner.
        ->assertSee("You're in", false)
        ->assertDontSee("That RSVP didn't save.", false)
        ->assertDontSeeHtml('data-testid="rsvp-failed"')
        // …but nothing is on its way, so "Syncing…" would be a lie.
        ->assertDontSeeHtml('data-testid="rsvp-syncing"')
        ->assertSeeHtml('data-testid="rsvp-sync-failed"');

    expect($rsvp->fresh()->synced_to_discord_at)->toBeNull();
});

it('stamps a dead grant instead of re-dispatching the denial', function () {
    // The shared fixture is itself reconcile-eligible (published, mirror null,
    // no stamp); mirror it so the reconcile assertion below speaks only about
    // the grant-owned row.
    $this->event->forceFill(['discord_event_id' => '1234567890'])->save();

    $grant = refusedEventGrant(['expires_at' => now()->subHour()]);

    $event = Event::factory()->create([
        'status' => EventStatus::Published,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
        'agent_grant_id' => $grant->getKey(),
        'created_by' => null,
    ]);

    $job = (new SyncEventToDiscord($event->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    $job->assertFailed()->assertNotReleased();

    // Same terminal shape as a bot refusal: reconcile must skip this row too.
    $fresh = $event->fresh();
    expect($fresh->discord_sync_failed_at)->not->toBeNull()
        ->and($fresh->discord_sync_failure_code)->toBe('grant_expired');

    Queue::fake();
    $this->artisan('events:reconcile')->assertSuccessful();
    Queue::assertNothingPushed();
});
