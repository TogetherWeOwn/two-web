<?php

use App\Enums\RsvpStatus;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;

use function Pest\Livewire\livewire;

// The events table shows fill (going/capacity) at a glance so a moderator
// scanning rows can see which events are filling without opening each one. A
// full event renders as a badge; the bare capacity number stays one toggle
// away. Only Going answers hold a seat — maybe/not-going/waitlisted never
// move the count (see Event::goingCount).

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
});

// Rendering a populated Filament table calls Number::format, which requires
// ext-intl. CI installs intl (ci.yml `extensions:` line); same skip shape as
// the sibling EventResourceServiceRoutingTest table tests.
function fillColumnFor(Event $event): TextColumn
{
    $component = livewire(ListEvents::class)->instance();

    /** @var TextColumn $column */
    $column = $component->getTable()->getColumn('going_count');

    $column->record($component->getTableRecord((string) $event->getKey()));
    $column->clearCachedState();

    return $column;
}

/** $count Going answers on the event, each from its own member. */
function fillSeats(Event $event, int $count): void
{
    Rsvp::factory()->count($count)->create([
        'event_id' => $event->id,
        'status' => RsvpStatus::Going,
    ]);
}

it('renders going over capacity on a partially filled event', function () {
    $event = Event::factory()->create(['capacity' => 4]);
    fillSeats($event, 2);

    $column = fillColumnFor($event);

    expect($column->formatState($column->getState()))->toBe('2/4')
        ->and($column->isBadge())->toBeFalse()
        ->and($column->getColor($column->getState()))->toBeNull();
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');

it('badges a full event', function () {
    $event = Event::factory()->create(['capacity' => 2]);
    fillSeats($event, 2);

    $column = fillColumnFor($event);

    expect($column->formatState($column->getState()))->toBe('2/2')
        ->and($column->isBadge())->toBeTrue()
        ->and($column->getColor($column->getState()))->toBe('warning');
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');

it('badges an over-capacity event', function () {
    // going can exceed capacity: two members can answer before either render
    // refreshes, so the badge must hold past the line, not just on it.
    $event = Event::factory()->create(['capacity' => 2]);
    fillSeats($event, 3);

    $column = fillColumnFor($event);

    expect($column->formatState($column->getState()))->toBe('3/2')
        ->and($column->isBadge())->toBeTrue()
        ->and($column->getColor($column->getState()))->toBe('warning');
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');

it('renders a bare count with no badge on an uncapped event', function () {
    $event = Event::factory()->create(['capacity' => null]);
    fillSeats($event, 3);

    $column = fillColumnFor($event);

    expect($column->formatState($column->getState()))->toBe('3')
        ->and($column->isBadge())->toBeFalse()
        ->and($column->getColor($column->getState()))->toBeNull();
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');

it('counts only going answers toward fill', function () {
    $event = Event::factory()->create(['capacity' => 5]);
    fillSeats($event, 1);
    Rsvp::factory()->create(['event_id' => $event->id, 'status' => RsvpStatus::Maybe]);
    Rsvp::factory()->create(['event_id' => $event->id, 'status' => RsvpStatus::NotGoing]);
    Rsvp::factory()->create(['event_id' => $event->id, 'status' => RsvpStatus::Waitlisted]);

    $column = fillColumnFor($event);

    expect($column->formatState($column->getState()))->toBe('1/5')
        ->and($column->isBadge())->toBeFalse();
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');
