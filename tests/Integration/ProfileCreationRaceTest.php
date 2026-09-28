<?php

use App\Models\Profile;
use App\Models\User;
use App\Support\Profiles\SaveMemberProfile;
use Illuminate\Support\Facades\DB;

/*
 * The first profile.
 *
 * `profiles.user_id` is unique and a first save is an INSERT: two concurrent
 * first saves running a bare `updateOrCreate([])` both miss the SELECT and
 * both INSERT, and the loser dies on `profiles_user_id_unique` (SQLSTATE
 * 23505). The writer serialises first-time creation behind `lockForUpdate()`
 * on the parent users row (see SaveMemberProfile), so the loser waits there
 * until the winner commits and its write becomes an UPDATE.
 *
 * This suite runs on committed data (DatabaseTruncation, not RefreshDatabase),
 * because the competitors are separate OS processes on their own connections
 * and cannot see rows sitting inside the test's own uncommitted transaction.
 */

/**
 * Launch a worker process and return its handle.
 *
 * @return array{process: resource, stdout: resource, stderr: resource}
 */
function startProfileRaceWorker(int $userId, int $startAtMs, string $tag): array
{
    $config = config('database.connections.pgsql');

    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'APP_ENV' => 'testing',
        'APP_KEY' => config('app.key'),
        'APP_TIMEZONE' => 'UTC',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'null',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $config['host'],
        'DB_PORT' => (string) $config['port'],
        'DB_DATABASE' => (string) $config['database'],
        'DB_USERNAME' => (string) $config['username'],
        'DB_PASSWORD' => (string) $config['password'],
    ];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open(
        [PHP_BINARY, base_path('tests/Support/profile_race_worker.php'), (string) $userId, (string) $startAtMs, $tag],
        $descriptors,
        $pipes,
        base_path(),
        $env,
    );

    expect($process)->toBeResource('process');

    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

/**
 * @param  array{process: resource, stdout: resource, stderr: resource}  $worker
 * @return array<string, mixed>
 */
function finishProfileRaceWorker(array $worker): array
{
    $stdout = stream_get_contents($worker['stdout']);
    $stderr = stream_get_contents($worker['stderr']);
    fclose($worker['stdout']);
    fclose($worker['stderr']);
    proc_close($worker['process']);

    $decoded = json_decode((string) $stdout, true);

    if (! is_array($decoded)) {
        throw new RuntimeException("Profile race worker produced no result.\nstdout: {$stdout}\nstderr: {$stderr}");
    }

    return $decoded;
}

/**
 * Where the other backends on this database are queued, as
 * [row-lock waiters, all lock waiters].
 *
 * The split is the mechanism, not just contention. `wait_event = 'tuple'` is a
 * backend blocked on a row-level lock — the `SELECT ... FOR UPDATE` on the
 * parent users row the writer takes before touching `profiles`. The second
 * worker in line chains behind the first worker's transaction
 * (`transactionid`), so with both workers piled up we see one of each: a total
 * of 2 with at least 1 on the row itself. A bare read-then-write
 * implementation never takes the parent lock; its losers collide later on the
 * speculative-insert `transactionid` wait instead, and the row count stays 0.
 *
 * @return array{int, int}
 */
function profileRaceLockQueue(): array
{
    // Postgres caches the statistics snapshot for the life of a transaction: the
    // first read of pg_stat_activity inside a transaction is the only one that
    // touches reality, and every later read in the same transaction hands back
    // that same snapshot. This poll runs inside the transaction that holds the
    // row lock, so without the clear it faithfully reports the world as it was
    // before the competitors connected.
    DB::select('select pg_stat_clear_snapshot()');

    $row = DB::selectOne(
        "select count(*) filter (where wait_event = 'tuple') as on_row,
                count(*) as total
           from pg_stat_activity
          where datname = current_database()
            and wait_event_type = 'Lock'
            and pid <> pg_backend_pid()",
    );

    return [(int) ($row->on_row ?? 0), (int) ($row->total ?? 0)];
}

it('serialises two concurrent first saves behind the parent row lock', function () {
    $member = User::factory()->create();

    // Hold the parent row from the test's own connection. Both workers will
    // then pile up behind *this* lock, which is how we know for certain that
    // their two transactions are open and overlapping before either is allowed
    // to proceed.
    //
    // The `finally` is not tidiness. If this test dies with the transaction
    // still open, the connection is recycled with the test app and the row lock
    // outlives it — and the next test's TRUNCATE waits on that lock forever,
    // turning one red test into a suite that never returns.
    $workers = [];
    $onRow = 0;
    $total = 0;

    try {
        DB::beginTransaction();
        User::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();

        $startAt = (int) (microtime(true) * 1000) + 750;
        $workers = [
            startProfileRaceWorker($member->id, $startAt, 'alpha'),
            startProfileRaceWorker($member->id, $startAt, 'beta'),
        ];

        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            [$onRow, $total] = profileRaceLockQueue();

            if ($onRow >= 1 && $total >= 2) {
                break;
            }

            usleep(25_000);
        }
    } finally {
        DB::rollBack();
    }

    $results = array_map(finishProfileRaceWorker(...), $workers);
    $outcomes = array_column($results, 'outcome');
    sort($outcomes);

    // Half one: they really did contend on the mechanism. Both workers piled up
    // behind this lock (total 2) with the queue headed on the member row itself
    // (tuple >= 1; the second worker chains on the first worker's transaction).
    // A bare read-then-write implementation never takes the member row lock —
    // its losers collide later on the speculative-insert `transactionid` wait
    // instead — so the row count stays 0 and this assertion is the one that
    // fails, before any of the counting below can accidentally pass.
    expect([$onRow, $total])->toBe(
        [1, 2],
        'Both first saves should have queued behind the member row lock '.
        "(row={$onRow}, total={$total}); a bare read-then-write never takes that lock. ".
        'Outcomes were: '.json_encode($results),
    );

    // Half two: both saves succeed, and exactly one profile row exists.
    expect($outcomes)->toBe(['saved', 'saved'], 'Outcomes were: '.json_encode($results));

    expect(Profile::query()->where('user_id', $member->id)->count())->toBe(1);
});

it('still updates an existing profile through the same writer', function () {
    $member = User::factory()->create();
    Profile::factory()->for($member)->create(['bio' => 'Before']);

    $profile = app(SaveMemberProfile::class)
        ->save($member, ['bio' => 'After', 'games' => ['Minecraft'], 'timezone' => 'Europe/London']);

    expect($profile->bio)->toBe('After')
        ->and(Profile::query()->where('user_id', $member->id)->count())->toBe(1)
        ->and($member->profile()->first())
        ->bio->toBe('After')
        ->games->toBe(['Minecraft'])
        ->timezone->toBe('Europe/London');
});
