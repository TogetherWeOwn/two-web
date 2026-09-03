<?php

/**
 * TOG-463 step 3, the `event.upsert` third: dispatch the REAL Laravel job onto a
 * REAL queue, run a REAL worker, and let it call a REAL running bot endpoint.
 *
 * Run from the two-web root with `php <this file>`. It expects BOT_ENDPOINT_URL,
 * BOT_KEY_ID and BOT_SHARED_SECRET already in the environment, pointed at a bot
 * started by the companion rig.
 *
 * What makes this the thing TOG-463 asked for rather than another unit test:
 * nothing here is faked. Http::fake() is not called, the queue driver is
 * `database`, and the job is taken off that queue by `queue:work` in a separate
 * process. The only double in the whole chain is Discord itself, which the bot
 * reaches through tools/mock-discord.
 *
 * Prints the request_id the bot minted, which is the join key into
 * internal_action_log for step 7.
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Jobs\SyncEventToDiscord;
use Illuminate\Support\Facades\DB;

// Discord-OAuth-only user model: the identity is `discord_id`, and there is no
// email or password column to fill in.
$creator = User::query()->firstOrCreate(
    ['discord_id' => '900000000000009999'],
    ['username' => 'tog463-acceptance', 'display_name' => 'TOG-463 acceptance'],
);

$eventKey = 'tog463-' . bin2hex(random_bytes(5));

$event = new Event([
    'title' => 'TOG-463 acceptance event',
    'game' => 'Rocket League',
    'description' => 'Dispatched by the real SyncEventToDiscord job. Ignore.',
    'starts_at' => now()->addDay(),
    'ends_at' => now()->addDay()->addHours(2),
    'timezone' => 'UTC',
    'location' => 'Acceptance run - ignore',
    'capacity' => 10,
    // Published, because SyncEventToDiscord returns without calling the bot for
    // any status where isMirroredInDiscord() is false — a Draft would make this
    // run look green while sending nothing.
    'status' => EventStatus::Published,
    'created_by' => $creator->id,
]);
$event->event_key = $eventKey;
$event->save();

echo "event_key      {$eventKey}\n";
echo "event id       {$event->id}\n";

$job = new SyncEventToDiscord($eventKey);
// The job debounces itself by 10s in the constructor. Waiting that out is not
// what is under test here, and `queue:work --once` would exit before it came
// due, so this run takes the delay off and leaves the debounce to its own unit
// test (tests/Feature/Jobs/SyncEventToDiscordTest.php).
$job->delay = null;

echo "idempotencyKey {$job->idempotencyKey}\n";

dispatch($job);

$queued = DB::table('jobs')->count();
echo "jobs on queue  {$queued}\n";

if ($queued < 1) {
    fwrite(STDERR, "FATAL nothing was queued; the job never reached the database driver.\n");
    exit(2);
}

echo "\n-- running the worker --\n";
$exit = 0;
passthru(
    PHP_BINARY . ' artisan queue:work --once --stop-when-empty --tries=1 -v 2>&1',
    $exit
);
echo "\nworker exit    {$exit}\n";

$event->refresh();
$remaining = DB::table('jobs')->count();
$failed = DB::table('failed_jobs')->count();

echo "jobs remaining {$remaining}\n";
echo "failed jobs    {$failed}\n";
echo "discord_event_id " . var_export($event->discord_event_id, true) . "\n";

if ($failed > 0) {
    $row = DB::table('failed_jobs')->latest('id')->first();
    fwrite(STDERR, "\nFAILED JOB:\n" . ($row->exception ?? '(no exception recorded)') . "\n");
    exit(1);
}

if (! is_string($event->discord_event_id) || $event->discord_event_id === '') {
    fwrite(STDERR, "\nNEEDS WORK the job ran but wrote back no discord_event_id.\n");
    exit(1);
}

echo "\nPASS the Laravel job drove event.upsert end to end and stored the Discord event id.\n";

// -- The retry: same job object, dispatched again ---------------------------
// This is the case the whole idempotency-key design exists for. The key is
// fixed in the constructor, so re-dispatching THIS job (not a new one) is what
// a queue retry after an unseen timeout looks like. A second Discord event here
// would be the bug.
echo "\n-- retry: re-dispatching the same job object --\n";
$before = $event->discord_event_id;
dispatch($job);
passthru(PHP_BINARY . ' artisan queue:work --once --stop-when-empty --tries=1 2>&1');
$event->refresh();
echo "discord_event_id before {$before}\n";
echo "discord_event_id after  {$event->discord_event_id}\n";
if ($event->discord_event_id !== $before) {
    fwrite(STDERR, "\nNEEDS WORK the retry produced a DIFFERENT Discord event id.\n");
    exit(1);
}
echo "PASS the retry reused the same Discord event — no duplicate.\n";
