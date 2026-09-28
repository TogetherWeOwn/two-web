<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\User;
use App\Support\RsvpRateLimit;

// TOG-6788: every throttled route answers a JSON caller with the same 429
// envelope — `{reason: rate_limited, message, retry_after}` plus the
// `Retry-After` header and no stack — no matter which limiter fired. Each
// test below hammers one throttle past its budget and runs the one shared
// assertion in tests/Support/ThrottleEnvelope.php.

it('answers the same JSON envelope on the login redirect throttle', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->getJson(route('login'));
    }

    assertThrottleEnvelope($this->getJson(route('login')));

    // A throttled handoff must not leak the OAuth URL either.
    expect((string) $this->getJson(route('login'))->getContent())->not->toContain('discord.com');
});

it('answers the same JSON envelope on the login callback throttle', function () {
    User::factory()->create(['username' => 'uniquenamexyz']);

    for ($i = 0; $i < 10; $i++) {
        $this->getJson('/auth/discord/callback?code=stale&state=wrong');
    }

    $throttled = $this->getJson('/auth/discord/callback?code=stale&state=wrong');

    assertThrottleEnvelope($throttled);

    // The throttle throws before the controller runs, so the member's presence
    // cannot shape the response body.
    expect((string) $throttled->getContent())->not->toContain('uniquenamexyz');
});

it('answers the same JSON envelope on the join redirect throttle', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->getJson(route('join.redirect'));
    }

    assertThrottleEnvelope($this->getJson(route('join.redirect')));
});

it('answers the same JSON envelope on the join callback throttle', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->getJson('/join/callback?code=stale&state=wrong');
    }

    $throttled = $this->getJson('/join/callback?code=stale&state=wrong');

    assertThrottleEnvelope($throttled);
    expect(User::query()->count())->toBe(0);
});

it('answers the same JSON envelope on the RSVP update throttle', function () {
    $member = User::factory()->create(['is_moderator' => false]);
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => null]);

    $this->freezeTime();

    for ($attempt = 0; $attempt < RsvpRateLimit::MAX_ATTEMPTS; $attempt++) {
        $this->actingAs($member)
            ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
            ->assertSuccessful();
    }

    assertThrottleEnvelope(
        $this->actingAs($member)
            ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value]),
        RsvpRateLimit::DECAY_SECONDS,
    );
});

it('answers the same JSON envelope on the RSVP destroy throttle', function () {
    $member = User::factory()->create(['is_moderator' => false]);
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => null]);

    $this->freezeTime();

    for ($attempt = 0; $attempt < RsvpRateLimit::MAX_ATTEMPTS; $attempt++) {
        $this->actingAs($member)
            ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
            ->assertSuccessful();
    }

    // Alternating verbs must not multiply the budget: the 13th write 429s
    // even though it is a DELETE, with the same envelope.
    assertThrottleEnvelope(
        $this->actingAs($member)->deleteJson(route('events.rsvp.destroy', $event)),
        RsvpRateLimit::DECAY_SECONDS,
    );
});

it('renders the branded 429 page without a stack for browser callers', function () {
    config(['app.debug' => false]);

    for ($i = 0; $i < 10; $i++) {
        $this->get('/auth/discord/callback?code=stale&state=wrong');
    }

    $this->get('/auth/discord/callback?code=stale&state=wrong')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertSee(__('errors.too_many_title'), escape: false)
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertSee('noindex', escape: false)
        ->assertDontSee('ThrottleRequestsException', escape: false);
});
