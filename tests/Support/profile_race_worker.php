<?php

declare(strict_types=1);
use App\Models\User;
use App\Support\Profiles\SaveMemberProfile;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/*
 * One competitor in the first-profile race (TOG-6966). It is a separate OS
 * process on purpose.
 *
 * PHP is single-threaded and a row lock blocks the process that issues it, so
 * two "concurrent" saves in one process cannot both be in flight — the second
 * one cannot even reach the lock until the first has finished. Two sequential
 * calls pass against a bare updateOrCreate, which is exactly the bug this is
 * meant to catch. So: two processes, two connections, two real transactions
 * overlapping in Postgres.
 *
 * Usage: php tests/Support/profile_race_worker.php <user_id> <start_at_ms> <tag>
 * Prints a single JSON object on stdout and exits 0 whatever happens; the parent
 * asserts on the outcomes, not on exit codes.
 */

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$userId = (int) ($argv[1] ?? 0);
$startAtMs = (int) ($argv[2] ?? 0);
$tag = (string) ($argv[3] ?? '');

try {
    $user = User::query()->findOrFail($userId);
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
    $profile = $app->make(SaveMemberProfile::class)
        ->save($user, ['bio' => "race-{$tag}", 'games' => [], 'timezone' => null]);

    echo json_encode(['outcome' => 'saved', 'profile_id' => $profile->id, 'bio' => $profile->bio]);
} catch (Throwable $e) {
    echo json_encode(['outcome' => 'error', 'exception' => $e::class, 'message' => $e->getMessage()]);
}

exit(0);
