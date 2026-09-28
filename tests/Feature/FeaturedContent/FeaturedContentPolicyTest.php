<?php

use App\Models\FeaturedContent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

// The admin panel rule: every verb on featured content is a moderator verb.
// The public landing page never asks this policy — it reads the
// `currentlyVisible` scope with no user at all (see
// FeaturedContentOnHomePageTest). What this pins is that a plain member gets
// nothing here, on any verb, including read.

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->row = FeaturedContent::factory()->create();
});

it('opens the admin listing only to moderators', function () {
    expect(Gate::forUser($this->moderator)->allows('viewAny', FeaturedContent::class))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('viewAny', FeaturedContent::class))->toBeFalse()
        ->and(Gate::allows('viewAny', FeaturedContent::class))->toBeFalse();
});

it('shows a featured row in the panel only to moderators', function () {
    expect(Gate::forUser($this->moderator)->allows('view', $this->row))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('view', $this->row))->toBeFalse();
});

it('lets only a moderator create featured content', function () {
    expect(Gate::forUser($this->moderator)->allows('create', FeaturedContent::class))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('create', FeaturedContent::class))->toBeFalse();
});

it('lets only a moderator change a featured row', function () {
    expect(Gate::forUser($this->moderator)->allows('update', $this->row))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('update', $this->row))->toBeFalse();
});

it('lets only a moderator remove a featured row', function () {
    expect(Gate::forUser($this->moderator)->allows('delete', $this->row))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('delete', $this->row))->toBeFalse();
});
