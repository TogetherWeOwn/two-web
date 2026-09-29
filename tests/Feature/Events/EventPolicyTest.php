<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// Policies, not controller tests: the rule is that a member cannot manage events
// and cannot answer on somebody else's behalf, and that rule has to hold wherever
// it is asked from — an HTTP route today, a console command or Livewire component
// tomorrow. Testing it through a controller only proves one caller wired it up.

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
    $this->member = User::factory()->create(['is_moderator' => false]);
});

it('lets only a moderator create an event', function () {
    expect(Gate::forUser($this->moderator)->allows('create', Event::class))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('create', Event::class))->toBeFalse();
});

it('lets only a moderator update an event', function () {
    $event = Event::factory()->create(['created_by' => $this->member->id]);

    expect(Gate::forUser($this->moderator)->allows('update', $event))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('update', $event))->toBeFalse();
});

it('lets only a moderator publish an event', function () {
    $event = Event::factory()->create(['status' => EventStatus::Draft]);

    expect(Gate::forUser($this->moderator)->allows('publish', $event))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('publish', $event))->toBeFalse();
});

it('lets only a moderator cancel an event', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    expect(Gate::forUser($this->moderator)->allows('cancel', $event))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('cancel', $event))->toBeFalse();
});

it('lets only a moderator pause or reopen answers', function () {
    // TOG-8725: pausing keeps a published event visible while stopping new
    // answers, so it must not be a member verb — like every other event
    // state change.
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    expect(Gate::forUser($this->moderator)->allows('toggleRsvp', $event))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('toggleRsvp', $event))->toBeFalse();
});

it('refuses new answers on a paused event but keeps the event visible', function () {
    // The pause closes the gate while leaving the event published: the page
    // still renders, but nobody answers.
    $event = Event::factory()->create(['status' => EventStatus::Published, 'rsvp_open' => false]);

    expect(Gate::forUser($this->member)->allows('view', $event))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('create', [Rsvp::class, $event, $this->member]))->toBeFalse();
});

it('hides a draft from members and shows it to moderators', function () {
    $draft = Event::factory()->create(['status' => EventStatus::Draft]);
    $published = Event::factory()->create(['status' => EventStatus::Published]);

    expect(Gate::forUser($this->member)->allows('view', $draft))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('view', $published))->toBeTrue()
        ->and(Gate::forUser($this->moderator)->allows('view', $draft))->toBeTrue();
});

it('lets a member RSVP only for themselves', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    $other = User::factory()->create();

    expect(Gate::forUser($this->member)->allows('create', [Rsvp::class, $event, $this->member]))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('create', [Rsvp::class, $event, $other]))->toBeFalse();
});

it('does not let even a moderator RSVP for somebody else', function () {
    // Moderating events is not the same permission as answering for a member.
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    expect(Gate::forUser($this->moderator)->allows('create', [Rsvp::class, $event, $this->member]))->toBeFalse();
});

it('does not let anybody RSVP to a draft or a cancelled event', function (EventStatus $status) {
    $event = Event::factory()->create(['status' => $status]);

    expect(Gate::forUser($this->member)->allows('create', [Rsvp::class, $event, $this->member]))->toBeFalse();
})->with([
    'draft' => EventStatus::Draft,
    'cancelled' => EventStatus::Cancelled,
]);

it('lets a member change or withdraw only their own answer', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    $mine = Rsvp::factory()->for($event)->for($this->member)->create();
    $theirs = Rsvp::factory()->for($event)->for(User::factory())->create();

    expect(Gate::forUser($this->member)->allows('update', $mine))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('delete', $mine))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('update', $theirs))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('delete', $theirs))->toBeFalse()
        ->and(Gate::forUser($this->moderator)->allows('delete', $theirs))->toBeFalse();
});

it('lets only a moderator delete an event', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    expect(Gate::forUser($this->moderator)->allows('delete', $event))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('delete', $event))->toBeFalse();
});

it('lists events for everybody, including guests', function () {
    // viewAny is the listing rule: drafts stay out of the listing through
    // viewDrafts below, never by hiding the page itself.
    expect(Gate::forUser($this->moderator)->allows('viewAny', Event::class))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('viewAny', Event::class))->toBeTrue()
        ->and(Gate::allows('viewAny', Event::class))->toBeTrue();
});

it('shows drafts in the listing only to moderators', function () {
    expect(Gate::forUser($this->moderator)->allows('viewDrafts', Event::class))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('viewDrafts', Event::class))->toBeFalse()
        ->and(Gate::allows('viewDrafts', Event::class))->toBeFalse();
});

it('shows a published event to guests but hides drafts from them', function () {
    $draft = Event::factory()->create(['status' => EventStatus::Draft]);
    $published = Event::factory()->create(['status' => EventStatus::Published]);

    expect(Gate::allows('view', $published))->toBeTrue()
        ->and(Gate::allows('view', $draft))->toBeFalse();
});
