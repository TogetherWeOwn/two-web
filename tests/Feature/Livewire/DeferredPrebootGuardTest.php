<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

// TOG-7927: the pre-boot guard. /events and /events/past boot Livewire
// deferred after window.load, so every `wire:click` control is unbound until
// the runtime arrives — a rendered "I'm in" button that takes the tap and does
// nothing, with no announcement. The layout answers that window two ways:
// buttons are disabled (the tap is impossible; a screen reader hears "dimmed",
// not silence) and link taps are stopped at capture and named politely through
// a role="status" node. `livewire:initialized` lifts both.
//
// What each test owns: the deferred tests pin the guard's static contract on
// both deferred pages — the status node, the guard script, the announced copy
// — and that the server markup itself is untouched (no `disabled` in SSR; the
// guard is client-side). The show-page test pins the other half: the
// non-deferred event page carries none of it, i.e. the conditional did not
// leak onto the page that boots Livewire inline. Behaviour inside the window
// (a tap that cannot land) is Dusk's ground, in EventsRsvpTest.
//
// NOTE: no shared HTML-fetch helper here. Pest's `test()` returns a TestCall,
// not the test case, so a top-level `guardPageHtml()` cannot reach `->get()`.
// Each test reads through `$this`, like DeferredLivewireStylesTest.

it('ships the pre-boot guard on the events page', function () {
    $html = (string) $this->get(route('events.index'))->assertOk()->getContent();

    // Non-vacuous: a guest on the events page sees wire:click controls (the
    // view toggles), so the guard has something to protect.
    expect($html)->toContain('wire:click');

    // The polite live region and its loading copy.
    expect($html)->toContain('data-testid="livewire-boot-status"')
        ->and($html)->toContain('role="status"')
        ->and($html)->toContain('Loading interactive controls');

    // The guard script: tags what it disables, listens for boot, and carries
    // both halves of the announced copy (blocked tap, ready state).
    expect($html)->toContain('data-preboot-disabled')
        ->and($html)->toContain('livewire:initialized')
        ->and($html)->toContain('Still loading — try again in a moment. Nothing changed.')
        ->and($html)->toContain('Interactive controls ready.');
});

it('leaves the RSVP control undisabled in server markup', function () {
    $member = User::factory()->create();
    Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $html = (string) $this->actingAs($member)
        ->get(route('events.index'))
        ->assertOk()
        ->getContent();

    // Non-vacuous: the signed-in member is offered the control.
    preg_match('/<button[^>]*data-testid="rsvp-going"[^>]*>/s', $html, $match);
    expect($match)->not->toBeEmpty('events page renders no rsvp-going button to guard');

    // The disabling is the guard script's job at parse time, not the
    // server's: a `disabled` here would survive boot and kill the control.
    // `disabled:` (the Tailwind `disabled:opacity-100` variant) is styling,
    // not the attribute — only a bare attribute counts.
    expect(preg_match('/\sdisabled(\s|=|>)/', $match[0]))->toBe(0);
});

it('ships the pre-boot guard on the past-events archive', function () {
    $html = (string) $this->get(route('events.past'))->assertOk()->getContent();

    expect($html)->toContain('data-testid="livewire-boot-status"')
        ->and($html)->toContain('data-preboot-disabled')
        ->and($html)->toContain('livewire:initialized');
});

it('keeps the pre-boot guard off the non-deferred event page', function () {
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $html = (string) $this->get(route('events.page', $event))->assertOk()->getContent();

    // Non-vacuous: this really is the share page, which boots Livewire inline.
    expect($html)->toContain('data-testid="event-page"');

    expect($html)->not->toContain('livewire-boot-status')
        ->and($html)->not->toContain('data-preboot-disabled');
});
