<?php

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Jobs\DeleteMemberData;
use App\Models\DataRequest;
use App\Models\Event;
use App\Models\FeaturedContent;
use App\Models\MemberDataAccessLog;
use App\Models\Profile;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\DataRequestService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

function decideRequest(DataRequest $request): DataRequest
{
    return DataRequest::query()->findOrFail($request->getKey());
}

it('approves a deletion request, stamps the decider, and queues the delete', function () {
    Queue::fake();

    $moderator = User::factory()->moderator()->create();
    $request = DataRequest::factory()->create(['type' => DataRequestType::Deletion]);

    expect(app(DataRequestService::class)->approve($request, $moderator))->toBeTrue();

    $fresh = decideRequest($request);

    expect($fresh->status)->toBe(DataRequestStatus::Approved)
        ->and($fresh->decided_by)->toBe($moderator->id)
        ->and($fresh->decided_at)->not->toBeNull();

    Queue::assertPushed(DeleteMemberData::class, fn ($job) => $job->dataRequestId === $request->id);
});

it('approves an export request with no delete queued', function () {
    Queue::fake();

    $moderator = User::factory()->moderator()->create();
    $request = DataRequest::factory()->create(['type' => DataRequestType::Export]);

    expect(app(DataRequestService::class)->approve($request, $moderator))->toBeTrue()
        ->and(decideRequest($request)->status)->toBe(DataRequestStatus::Approved);

    Queue::assertNotPushed(DeleteMemberData::class);
});

it('rejects with a reason code and queues nothing', function () {
    Queue::fake();

    $moderator = User::factory()->moderator()->create();
    $request = DataRequest::factory()->create(['type' => DataRequestType::Deletion]);

    expect(app(DataRequestService::class)->reject($request, $moderator, 'identity-mismatch'))->toBeTrue();

    $fresh = decideRequest($request);

    expect($fresh->status)->toBe(DataRequestStatus::Rejected)
        ->and($fresh->decision_reason)->toBe('identity-mismatch');

    Queue::assertNotPushed(DeleteMemberData::class);
});

it('refuses an unknown rejection reason', function () {
    $moderator = User::factory()->moderator()->create();
    $request = DataRequest::factory()->create();

    expect(fn () => app(DataRequestService::class)->reject($request, $moderator, 'vibes'))
        ->toThrow(InvalidArgumentException::class);
});

it('decides a row exactly once — the second decision is a no-op', function () {
    Queue::fake();

    $moderator = User::factory()->moderator()->create();
    $other = User::factory()->moderator()->create();
    $request = DataRequest::factory()->create(['type' => DataRequestType::Deletion]);

    expect(app(DataRequestService::class)->approve($request, $moderator))->toBeTrue();
    expect(app(DataRequestService::class)->reject($request, $other, 'identity-mismatch'))->toBeFalse();

    $fresh = decideRequest($request);

    expect($fresh->status)->toBe(DataRequestStatus::Approved)
        ->and($fresh->decided_by)->toBe($moderator->id);

    Queue::assertPushed(DeleteMemberData::class, 1);
});

it('deletes the member row, profile and RSVPs on an approved deletion, and keeps the audit trail', function () {
    $moderator = User::factory()->moderator()->create();
    $member = User::factory()->create();
    Profile::factory()->for($member)->create(['bio' => 'Going away.']);
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Rsvp::factory()->for($member)->for($event)->create(['status' => RsvpStatus::Going]);
    $kept = FeaturedContent::factory()->create(['created_by' => $member->id]);
    $watched = MemberDataAccessLog::query()->create([
        'viewer_discord_id' => $moderator->discord_id,
        'viewer_user_id' => $moderator->id,
        'resource' => 'member',
        'action' => 'view',
        'subject_user_ids' => [$member->id],
        'subject_count' => 1,
        'route' => 'profiles.show',
        'occurred_at' => now(),
    ]);
    $request = DataRequest::factory()->for($member, 'requester')->create([
        'discord_id' => $member->discord_id,
        'type' => DataRequestType::Deletion,
    ]);

    app(DataRequestService::class)->approve($request, $moderator);
    (new DeleteMemberData($request->id))->handle();

    expect(User::query()->whereKey($member->id)->exists())->toBeFalse()
        ->and(Profile::query()->where('user_id', $member->id)->exists())->toBeFalse()
        ->and(Rsvp::query()->where('user_id', $member->id)->exists())->toBeFalse()
        // Events the member created stay up, unattributed.
        ->and($kept->fresh()->created_by)->toBeNull()
        // The access-log row survives with the viewer nulled only when the
        // viewer is deleted; here the moderator lives, so the row is intact.
        ->and($watched->fresh()->viewer_user_id)->toBe($moderator->id)
        // The queue row survives with the member nulled — the decision is the
        // audit trail, and deleting the member must not erase it.
        ->and(decideRequest($request)->user_id)->toBeNull()
        ->and(decideRequest($request)->discord_id)->toBe($member->discord_id);
});

it('re-running the delete after the member is gone changes nothing', function () {
    $moderator = User::factory()->moderator()->create();
    $member = User::factory()->create();
    $request = DataRequest::factory()->for($member, 'requester')->create([
        'discord_id' => $member->discord_id,
        'type' => DataRequestType::Deletion,
    ]);

    app(DataRequestService::class)->approve($request, $moderator);
    (new DeleteMemberData($request->id))->handle();
    (new DeleteMemberData($request->id))->handle();

    expect(User::query()->whereKey($member->id)->exists())->toBeFalse()
        ->and(decideRequest($request)->status)->toBe(DataRequestStatus::Approved);
});

it('records the approve/reject decision in the activity log', function () {
    $moderator = User::factory()->moderator()->create();
    $this->actingAs($moderator);

    $approved = DataRequest::factory()->create(['type' => DataRequestType::Export]);
    app(DataRequestService::class)->approve($approved, $moderator);

    $rejected = DataRequest::factory()->create(['type' => DataRequestType::Deletion]);
    app(DataRequestService::class)->reject($rejected, $moderator, 'identity-mismatch');

    expect(Activity::query()->where('subject_type', DataRequest::class)->where('event', 'updated')->count())
        ->toBeGreaterThanOrEqual(2);
});

it('prunes closed rows past the retention window and never a pending ask', function () {
    Carbon::setTestNow('2026-09-10T12:00:00Z');
    config()->set('data-requests.retention_days', 90);

    $backdate = fn (DataRequest $row, int $days) => DataRequest::query()
        ->whereKey($row->id)->update([
            'created_at' => now()->subDays($days),
            'updated_at' => now()->subDays($days),
        ]);

    $staleApproved = DataRequest::factory()->create(['status' => DataRequestStatus::Approved]);
    $backdate($staleApproved, 91);

    $keptRejected = DataRequest::factory()->create(['status' => DataRequestStatus::Rejected]);
    $backdate($keptRejected, 89);

    $pending = DataRequest::factory()->create(['status' => DataRequestStatus::Pending]);
    $backdate($pending, 200);

    $this->artisan('model:prune', ['--model' => [DataRequest::class]])->assertSuccessful();

    expect(DataRequest::query()->pluck('id')->all())
        ->toEqualCanonicalizing([$keptRejected->id, $pending->id]);

    Carbon::setTestNow();
});

it('has retention scheduled daily, because a retention policy nothing runs is a promise', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'model:prune')
            && str_contains($event->command ?? '', 'DataRequest'));

    expect($events)->not->toBeEmpty();
    $events->each(fn ($event) => expect($event->getExpression())->toBe('0 0 * * *'));
});
