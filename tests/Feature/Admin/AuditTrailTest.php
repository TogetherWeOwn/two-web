<?php

use App\Models\Event;
use App\Models\FeaturedContent;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

// The card's third requirement: who changed what, when. The trait hangs off
// model events, so these prove the wiring at the model layer — every write
// path, panel or not, goes through it.

it('records who changed featured content and what changed', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $this->actingAs($moderator);

    $row = FeaturedContent::factory()->create(['title' => 'Before']);
    $row->update(['title' => 'After']);

    $activity = Activity::query()
        ->where('subject_type', FeaturedContent::class)
        ->where('subject_id', $row->id)
        ->where('event', 'updated')
        ->sole();

    expect($activity->causer_id)->toBe($moderator->id)
        ->and($activity->changes()['attributes']['title'])->toBe('After')
        ->and($activity->changes()['old']['title'])->toBe('Before');
});

it('writes no audit row for a save that changed nothing', function () {
    $row = FeaturedContent::factory()->create();
    $countAfterCreate = Activity::query()->count();

    $row->save();

    expect(Activity::query()->count())->toBe($countAfterCreate);
});

it('records event changes with the acting moderator as causer', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $this->actingAs($moderator);

    $event = Event::factory()->create(['title' => 'Old title']);
    $event->update(['title' => 'New title']);

    $activity = Activity::query()
        ->where('subject_type', Event::class)
        ->where('subject_id', $event->id)
        ->where('event', 'updated')
        ->sole();

    expect($activity->causer_id)->toBe($moderator->id);
});
