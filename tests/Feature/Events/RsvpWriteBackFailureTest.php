<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Jobs\SyncEventToDiscord;
use App\Livewire\RsvpButton;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\Bot\InternalActionClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * TOG-6778: the bot is down, and the member must never be able to tell from
 * their RSVP — because nothing about the RSVP failed.
 *
 * Each half of this is already pinned elsewhere: the job releases rather than
 * failing on a transport error (SyncEventToDiscordTest), and the button renders
 * "Saved. Syncing to Discord." for a null `synced_to_discord_at`
 * (RsvpButtonTest). What no test pins is the chain: a write-back that actually
 * fails, the member-visible state between the failure and the retry, and the
 * retry that closes it. Split across files, those three halves can drift —
 * the job could start failing on transport errors, or the button could start
 * rendering a failure for an unsynced row, and both existing files would stay
 * green while the member's promise broke.
 */

beforeEach(function () {
    config()->set('services.bot.url', 'http://bot.internal:3001');
    config()->set('services.bot.secret', 'two-web-test-secret-at-least-32-characters');
    config()->set('services.bot.key_id', 'web-test');
    config()->set('services.bot.timeout', 5);

    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 4,
    ]);
});

/**
 * One bot stub for the whole outage: down until the test says otherwise.
 *
 * A single fake on purpose. `Http::fake()` *merges* stub callbacks, so a
 * second `Http::fake()` for the recovery phase would never replace the first
 * closure — the recovery `handle()` would still see "connection refused" and
 * the test would fail while the implementation is correct.
 *
 * @return Closure(): void flips the bot back to reachable
 */
function botOutage(): Closure
{
    $botDown = true;

    Http::fake(function () use (&$botDown) {
        if ($botDown) {
            throw new ConnectionException('Connection refused');
        }

        return Http::response([
            'ok' => true,
            'result' => ['outcome' => 'created', 'event_id' => '1234567890'],
            'request_id' => '01JRECOVER0123456789',
        ]);
    });

    // Not an arrow function: `fn () => $botDown = false` would assign to a copy.
    return function () use (&$botDown): void {
        $botDown = false;
    };
}

it('keeps the RSVP as truthful pending while the bot is down and syncs it when the bot recovers', function () {
    // Phase 1 — the bot is down. The write-back must release (retry), never
    // fail: the RSVP is already committed, so this is a wait, not a failure.
    $recoverBot = botOutage();

    $rsvp = Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
        'synced_to_discord_at' => null,
    ]);

    $job = (new SyncEventToDiscord($this->event->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    // Released, not failed: the retry — and the reconcile pass behind it — is
    // the whole recovery path. A fail() here writes `failed_jobs` and moves on,
    // and for a quiet event nothing else ever notices.
    $job->assertReleased()->assertNotFailed();

    // The row is the evidence: saved here, not yet in Discord. Silent loss
    // would be this row gone, or marked synced when it is not.
    expect($rsvp->fresh()->synced_to_discord_at)->toBeNull()
        ->and($this->event->fresh()->discord_event_id)->toBeNull();

    // Phase 2 — the member looks at the page while the bot is still down. The
    // pending state must read as exactly that: committed here, Discord catching
    // up. It must never read as a failure — a failure banner tells somebody
    // their RSVP did not save while it is sitting in the database, and they
    // stop coming.
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        ->assertSee("You're in", false)
        ->assertSee('Saved. Syncing to Discord.')
        ->assertSeeHtml('data-testid="rsvp-syncing"')
        ->assertDontSee("That RSVP didn't save.", false)
        ->assertDontSeeHtml('data-testid="rsvp-failed"');

    // Phase 3 — the bot recovers. The same operation retried on the same job
    // instance (which is how the worker replays it) must land: Discord gets
    // the mirror, the answer is stamped, and the member sees synced.
    $recoverBot();

    $job->handle(app(InternalActionClient::class));

    expect($this->event->fresh()->discord_event_id)->toBe('1234567890')
        ->and($rsvp->fresh()->synced_to_discord_at)->not->toBeNull();

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        ->assertSee("You're in", false)
        ->assertSee('Synced to Discord.')
        ->assertSeeHtml('data-testid="rsvp-synced"')
        ->assertDontSeeHtml('data-testid="rsvp-syncing"');
});

it('keeps the RSVP pending, not failed, when the write-back is only released and never retried yet', function () {
    // The narrow half of the chain, without the recovery: a job that released
    // (bot unreachable) must leave a row the button renders as syncing. This is
    // the state the member sits in for the whole outage — the reconcile pass
    // closes it within ten minutes of recovery, but until then this banner is
    // the entire contract.
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $rsvp = Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
        'synced_to_discord_at' => null,
    ]);

    $job = (new SyncEventToDiscord($this->event->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    $job->assertReleased()->assertNotFailed();

    expect($rsvp->fresh()->exists)->toBeTrue('the retry path must never cost the member their row')
        ->and($rsvp->fresh()->synced_to_discord_at)->toBeNull();

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        ->assertSee("You're in", false)
        ->assertSeeHtml('data-testid="rsvp-syncing"')
        ->assertDontSeeHtml('data-testid="rsvp-failed"');
});
