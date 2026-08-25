<?php

declare(strict_types=1);

/*
 * One competitor in the last-slot race. It is a separate OS process on purpose.
 *
 * PHP is single-threaded and `lockForUpdate()` blocks the process that issues it,
 * so two "concurrent" transactions in one process cannot both be in flight — the
 * second one cannot even reach the lock until the first has finished. Two
 * sequential calls pass against a read-then-write implementation, which is exactly
 * the bug this is meant to catch. So: two processes, two connections, two real
 * transactions overlapping in Postgres.
 *
 * Usage: php tests/Support/rsvp_race_worker.php <event_key> <user_id> <start_at_ms>
 * Prints a single JSON object on stdout and exits 0 whatever happens; the parent
 * asserts on the outcomes, not on exit codes.
 */

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$eventKey = (string) ($argv[1] ?? '');
$userId = (int) ($argv[2] ?? 0);
$startAtMs = (int) ($argv[3] ?? 0);

try {
    $event = App\Models\Event::query()->where('event_key', $eventKey)->firstOrFail();
    $user = App\Models\User::query()->findOrFail($userId);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'setup_failed', 'exception' => $e::class, 'message' => $e->getMessage()]);
    exit(0);
}

// Spin — not sleep — to the shared instant, so both processes enter the critical
// section within a millisecond of each other even if one booted faster.
while ((int) (microtime(true) * 1000) < $startAtMs) {
    usleep(100);
}

try {
    $rsvp = $app->make(App\Services\EventService::class)
        ->rsvp($event, $user, App\Enums\RsvpStatus::Going);

    echo json_encode(['outcome' => 'accepted', 'rsvp_id' => $rsvp->id]);
} catch (App\Exceptions\EventAtCapacityException $e) {
    echo json_encode(['outcome' => 'at_capacity', 'exception' => $e::class, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'error', 'exception' => $e::class, 'message' => $e->getMessage()]);
}

exit(0);
