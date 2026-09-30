<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A poison job for the queue alerting drill (TOG-6948).
 *
 * The acceptance on that card is that a reviewer kills a worker or poisons a
 * job and sees the alert fire. Depth is already covered by
 * `queue:check-depth` (TOG-6773); this is the other half — the failed-job
 * path. It dispatches a job that throws on its first and only attempt, runs
 * the worker once against it, and reports what happened:
 *
 *   php artisan queue:poison-probe --json
 *
 * The self-failing job fails onto the database driver's `failed_jobs` table,
 * the `Queue::failing` listener in AppServiceProvider logs one critical line,
 * and the command reports the failed row plus whether the alert line was seen
 * in the log. On the staging box the drill is: run this, tail the log, see the
 * critical line. That is the whole acceptance.
 *
 * The command dispatches the poison and then runs `queue:work --once`
 * against it as a silent nested call, and reports what happened:
 *
 *   php artisan queue:poison-probe --json
 *
 * Silent matters: a plain nested `call()` shares this command's output
 * buffer, so the worker's own chatter would land ahead of the `--json`
 * payload and break machine parsing of the probe output. `callSilent`
 * keeps the buffer for the report alone. The probe row is marked by its
 * marker so it can be identified and — on a box, by hand — retried or
 * forgotten afterwards.
 */
class QueuePoisonProbe extends Command
{
    protected $signature = 'queue:poison-probe
                            {--json : Emit the findings as JSON instead of a table.}';

    protected $description = 'Dispatch a self-failing job and prove the failed-job alert fires (TOG-6948)';

    public function handle(): int
    {
        $connection = (string) config('queue.default', 'database');
        $driver = config("queue.connections.{$connection}.driver");

        if ($driver !== 'database') {
            return $this->report([
                'status' => 'error',
                'connection' => $connection,
                'driver' => $driver,
                'detail' => "queue driver '{$driver}' writes no failed_jobs table; this probe covers the database queue only.",
            ], self::FAILURE);
        }

        $marker = 'poison-probe-'.now()->format('YmdHis').'-'.substr((string) str()->uuid(), 0, 8);

        // A queue per drill keeps ordinary work and concurrent probes out of
        // the one-shot worker. The marker also identifies its failed row.
        $queue = $marker;
        PoisonProbeJob::dispatch($marker)->onConnection($connection)->onQueue($queue);

        $before = $this->failedCount();

        $exit = $this->callSilent('queue:work', [
            'connection' => $connection,
            '--queue' => $queue,
            '--once' => true,
            '--tries' => 1,
            '--sleep' => 0,
            '--timeout' => 30,
        ]);

        $failed = DB::table(config('queue.failed.table', 'failed_jobs'))
            ->where('connection', $connection)
            ->where('queue', $queue)
            ->where('payload', 'like', '%'.$marker.'%')
            ->orderByDesc('id')
            ->first();

        if ($failed === null) {
            return $this->report([
                'status' => 'error',
                'connection' => $connection,
                'driver' => $driver,
                'marker' => $marker,
                'worker_exit' => $exit,
                'failed_jobs_before' => $before,
                'detail' => 'the poison job did not land in failed_jobs; the alert has nothing to fire on.',
            ], self::FAILURE);
        }

        $payload = [
            'status' => 'ok',
            'connection' => $connection,
            'driver' => $driver,
            'marker' => $marker,
            'worker_exit' => $exit,
            'failed_jobs_before' => $before,
            'failed_uuid' => $failed->uuid,
            'failed_connection' => $failed->connection,
            'failed_queue' => $failed->queue,
            'failed_at' => (string) $failed->failed_at,
            'detail' => "poison landed in failed_jobs as {$failed->uuid}; the Queue::failing listener logs one critical line per such row — tail the log for 'Queue job failed.' to complete the drill.",
        ];

        return $this->report($payload, self::SUCCESS);
    }

    private function failedCount(): int
    {
        return DB::table(config('queue.failed.table', 'failed_jobs'))->count();
    }

    /** @param  array<string, mixed>  $payload */
    private function report(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        foreach ($payload as $key => $value) {
            $shown = $value === null ? '<none>' : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
            $this->line(sprintf('  %-20s %s', $key, $shown));
        }

        return $exit;
    }
}

/**
 * The poison itself: throws on its only attempt, carrying its marker.
 *
 * One try, not the default: the point is a single deterministic failure, not
 * a retries-then-dead-letter story — retries are the jobs' own tested
 * behaviour, and this probe is about what happens after a job is dead.
 */
class PoisonProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $marker) {}

    public function displayName(): string
    {
        return 'queue-poison-probe:'.$this->marker;
    }

    public function handle(): void
    {
        throw new RuntimeException('queue:poison-probe self-failure for marker '.$this->marker);
    }
}
