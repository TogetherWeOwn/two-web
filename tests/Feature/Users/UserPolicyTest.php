<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

// Same shape as EventPolicyTest: the rule, not the caller. `view` is the auth
// boundary (ProfileController and MemberProfile both ask it); `updateProfile`
// is the self-only rule (MemberProfile and UpdateProfileRequest both ask it).

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
    $this->member = User::factory()->create(['is_moderator' => false]);
});

it('lets any signed-in member view any member profile', function () {
    $other = User::factory()->create();

    expect(Gate::forUser($this->member)->allows('view', $other))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('view', $this->member))->toBeTrue()
        ->and(Gate::forUser($this->moderator)->allows('view', $other))->toBeTrue();
});

it('keeps guests outside the member directory', function () {
    // Authentication is the privacy boundary: a signed-out visitor gets
    // nothing, even though every signed-in member gets everything.
    expect(Gate::allows('view', $this->member))->toBeFalse();
});

it('lets a member edit only their own profile', function () {
    $other = User::factory()->create();

    expect(Gate::forUser($this->member)->allows('updateProfile', $this->member))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('updateProfile', $other))->toBeFalse()
        ->and(Gate::forUser($other)->allows('updateProfile', $this->member))->toBeFalse();
});

it('gives a moderator no extra rights over somebody elses profile', function () {
    // Moderating events is not the same permission as editing a member.
    expect(Gate::forUser($this->moderator)->allows('updateProfile', $this->member))->toBeFalse()
        ->and(Gate::forUser($this->moderator)->allows('updateProfile', $this->moderator))->toBeTrue();
});
