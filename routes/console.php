<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
