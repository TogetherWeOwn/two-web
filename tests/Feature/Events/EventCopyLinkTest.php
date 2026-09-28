<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

// TOG-7262: copy-event-link button on the shareable event page. The button
// carries the canonical event URL in `data-copy-link` (the contract with
// resources/js/event-copy-link.js) and a toast slot lives in the page for the
// confirmation. Clipboard behaviour itself is browser-only —
// `navigator.clipboard.writeText` with an execCommand fallback — so Pest pins
// the markup and the URL, not the copy. Same pattern as the member profile
// (TOG-6926), kept event-scoped so either page can change without touching
// the other.
//
// Unlike member profiles, the event page is public: guests and members get the
// same control, because the copied link is the shareable one.

it('shows a guest the copy button carrying the canonical event URL', function () {
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertSee('Copy link', false)
        ->assertSeeHtml('data-testid="event-copy-link"')
        ->assertSeeHtml('data-copy-link="'.route('events.page', $event).'"')
        ->assertSeeHtml('data-testid="event-copy-toast"')
        ->assertSeeHtml('role="status"');
});

it('shows a signed-in member the same copy control', function () {
    $member = User::factory()->create(['is_moderator' => false]);
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $this->actingAs($member)
        ->get(route('events.page', $event))
        ->assertOk()
        ->assertSee('Copy link', false)
        ->assertSeeHtml('data-testid="event-copy-link"')
        ->assertSeeHtml('data-copy-link="'.route('events.page', $event).'"')
        ->assertSeeHtml('data-testid="event-copy-toast"')
        ->assertSeeHtml('role="status"');
});

it('shows the copy control on a draft preview for moderators', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $draft = Event::factory()->create([
        'title' => 'Unannounced game night',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Draft,
    ]);

    $this->actingAs($moderator)
        ->get(route('events.page', $draft))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-copy-link"')
        ->assertSeeHtml('data-copy-link="'.route('events.page', $draft).'"')
        ->assertSeeHtml('data-testid="event-copy-toast"');
});

it('carries no copy control on the cancelled gone page', function () {
    // The 410 page is a separate template with nothing to share from: the
    // event is off, so there is no live page to copy a link to. This pins the
    // scope — the live shareable page owns the control, the gone page stays
    // a dead end with words.
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Cancelled,
    ]);

    $this->get(route('events.page', $event))
        ->assertStatus(410)
        ->assertDontSeeHtml('data-testid="event-copy-link"')
        ->assertDontSeeHtml('data-copy-link');
});
