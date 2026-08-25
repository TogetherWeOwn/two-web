<?php

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
