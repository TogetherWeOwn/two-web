<?php

use App\Enums\RsvpStatus;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\RelationManagers\RsvpsRelationManager;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;

use function Pest\Livewire\livewire;

// The RSVP roster on the event admin page: a read-only relation manager
// showing who answered what and when. Same three questions as PanelAccessTest
// and ResourceAuthorizationTest — guest out, member 403, moderator in — plus
// the roster's own contract: the rows render, and no write action exists to
// answer on a member's behalf (RsvpPolicy answers that question the same way:
// a moderator gets nothing extra there).

function rosterEventWithAnswers(): Event
{
    $event = Event::factory()->create();

    Rsvp::factory()->for($event)->create([
        'user_id' => User::factory()->create(['display_name' => 'Roster Going Member'])->id,
        'status' => RsvpStatus::Going,
    ]);
    Rsvp::factory()->for($event)->create([
        'user_id' => User::factory()->create(['display_name' => 'Roster Maybe Member'])->id,
        'status' => RsvpStatus::Maybe,
    ]);

    return $event;
}

/** @return array{ownerRecord: Event, pageClass: class-string} */
function rosterParams(Event $event): array
{
    return ['ownerRecord' => $event, 'pageClass' => EditEvent::class];
}

it('redirects a guest from the event edit page carrying the roster to login', function () {
    $event = rosterEventWithAnswers();

    $this->get("/admin/events/{$event->getRouteKey()}/edit")->assertRedirect(route('login'));
});

it('answers a plain member with 403 on the event edit page carrying the roster', function () {
    $member = User::factory()->create(['is_moderator' => false]);
    $event = rosterEventWithAnswers();

    $this->actingAs($member)->get("/admin/events/{$event->getRouteKey()}/edit")->assertForbidden();
});

it('lets a moderator open the event edit page carrying the roster', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $event = rosterEventWithAnswers();

    $this->actingAs($moderator)->get("/admin/events/{$event->getRouteKey()}/edit")->assertOk();
});

it('shows a moderator the RSVP rows with member, status and answered-at', function () {
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
    $event = rosterEventWithAnswers();
    $rsvps = $event->rsvps()->orderBy('id')->get();

    livewire(RsvpsRelationManager::class, rosterParams($event))
        ->assertCanSeeTableRecords($rsvps)
        ->assertCountTableRecords(2)
        ->assertCanRenderTableColumn('user.display_name')
        ->assertCanRenderTableColumn('status')
        ->assertCanRenderTableColumn('updated_at')
        ->assertSee('Roster Going Member')
        ->assertSee('Roster Maybe Member');
});

it('scopes the roster to its own event', function () {
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
    $event = rosterEventWithAnswers();
    $other = Event::factory()->create();
    $stranger = Rsvp::factory()->for($other)->create();

    livewire(RsvpsRelationManager::class, rosterParams($event))
        ->assertCanSeeTableRecords($event->rsvps()->orderBy('id')->get())
        ->assertCanNotSeeTableRecords([$stranger])
        ->assertCountTableRecords(2);
});

it('offers no write action on the roster', function () {
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
    $event = rosterEventWithAnswers();

    livewire(RsvpsRelationManager::class, rosterParams($event))
        ->assertTableHeaderActionsExistInOrder([])
        ->assertTableActionsExistInOrder([])
        ->assertTableBulkActionsExistInOrder([])
        ->assertTableActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});

it('refuses a plain member at the roster component with 403', function () {
    // Defence in depth behind the panel gate: the edit page filters its
    // managers through `canViewForRecord` before mounting, and the manager
    // itself re-checks RsvpPolicy::viewAny in `boot`, so even a route that
    // mounted it without the page's own check still turns a member away. The
    // denial surfaces as a 403 on the initial render, with no component and
    // no rows behind it.
    $this->actingAs(User::factory()->create(['is_moderator' => false]));
    $event = rosterEventWithAnswers();

    $testable = livewire(RsvpsRelationManager::class, rosterParams($event));

    $lastState = (fn (): mixed => $this->lastState)->call($testable);

    expect($lastState->getResponse()->getStatusCode())->toBe(403)
        ->and($lastState->getComponent())->toBeNull();
});
