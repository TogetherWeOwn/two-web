<?php

use App\Models\AgentEventIdempotencyKey;
use App\Models\DataRequest;
use App\Models\JoinAttempt;
use App\Models\MemberDataAccessLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention on the member data access log. `model:prune` runs the mass delete on
// MemberDataAccessLog::prunable(), which can only ever match rows older than the
// configured window — it is not a general delete anyone can point somewhere else.
//
// If the scheduler is not running, this table grows forever rather than losing
// data, which is the right way round for a log but is still a thing to notice:
// tests/Feature/MemberDataAccessLogTest.php asserts the schedule exists.
Schedule::command('model:prune', ['--model' => [MemberDataAccessLog::class]])->daily();

// Retention on the join funnel and the agent replay store (TOG-8710).
// `model:prune` runs the mass delete on each model's prunable(), which can
// only ever match rows older than that model's configured window — it is not
// a general delete anyone can point somewhere else. One entry for both
// models: a single daily pass, same as the access-log entry above, keeps the
// schedule readable and the prune cost to one wake per model.
//
// If the scheduler is not running, both tables grow forever rather than
// losing data, which is the right way round for a funnel and a replay store
// but is still a thing to notice:
// tests/Feature/Console/PruneStaleRetentionTest.php asserts the schedule
// exists and that both prunable() scopes keep rows inside the window.
Schedule::command('model:prune', ['--model' => [JoinAttempt::class, AgentEventIdempotencyKey::class]])->daily();

// Retention on closed data requests (TOG-8705). `model:prune` runs the mass
// delete on DataRequest::prunable(), which can only ever match closed rows
// older than the configured window — never a pending ask, which is live
// work, not history. Same shape as the two entries above.
Schedule::command('model:prune', ['--model' => [DataRequest::class]])->daily();

/*
 * Every ten minutes, because that is the gap between an event going stale and a
 * member seeing the stale version. It is cheap when there is nothing to do: two
 * indexed queries that return no rows.
 *
 * `withoutOverlapping` because a long Discord outage makes a pass slow, and two
 * passes running together would dispatch the same write-backs twice. The job's own
 * uniqueness would absorb it, but a second lock costs nothing and the queue never
 * sees the duplicates at all.
 *
 * `runInBackground` so a slow reconcile cannot delay whatever the scheduler runs
 * next, and `onOneServer` so adding a second web box does not double every pass.
 */
Schedule::command('events:reconcile')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onOneServer();
