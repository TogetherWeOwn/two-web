<?php

use App\Enums\JoinOutcome;
use App\Models\JoinAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// The admin funnel rule: join-attempt rows are written by JoinController
// itself and read only by the admin JoinFunnelStats widget, so every human
// verb is a moderator verb — and the write verbs are denied to everybody,
// moderators included, because no person ever creates, edits or deletes a
// row. Policies, not widget tests: the rule has to hold wherever it is asked
// from, not just on today's widget.

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->row = JoinAttempt::query()->create(['outcome' => JoinOutcome::Added]);
});

it('opens the join-attempt listing only to moderators', function () {
    expect(Gate::forUser($this->moderator)->allows('viewAny', JoinAttempt::class))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('viewAny', JoinAttempt::class))->toBeFalse()
        ->and(Gate::allows('viewAny', JoinAttempt::class))->toBeFalse();
});

it('shows a join-attempt row only to moderators', function () {
    expect(Gate::forUser($this->moderator)->allows('view', $this->row))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('view', $this->row))->toBeFalse()
        ->and(Gate::allows('view', $this->row))->toBeFalse();
});

it('lets nobody create a join-attempt row, not even a moderator', function () {
    // Rows are written by JoinController alongside the join itself; there is
    // no flow in which a signed-in person creates one.
    expect(Gate::forUser($this->moderator)->allows('create', JoinAttempt::class))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('create', JoinAttempt::class))->toBeFalse();
});

it('lets nobody change a join-attempt row, not even a moderator', function () {
    expect(Gate::forUser($this->moderator)->allows('update', $this->row))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('update', $this->row))->toBeFalse();
});

it('lets nobody remove a join-attempt row, not even a moderator', function () {
    expect(Gate::forUser($this->moderator)->allows('delete', $this->row))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('delete', $this->row))->toBeFalse();
});
