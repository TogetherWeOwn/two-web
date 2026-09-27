<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Pending-job depth of the Postgres queue (TOG-6773).
 *
 * Sessions, cache and queue all run on Postgres, and the RSVP write-back to
 * Discord is async: the row is committed, the job is dispatched after the
 * commit, and the member's answer sits at `synced_to_discord_at = null` until
 * a worker picks the job up. A silent queue backlog is therefore silent RSVP
 * delay — no 500, no log line, the Discord mirror just stops catching up.
 * This command is the box-side probe for that failure, in the same tradition
 * as `discord:check-moderators`: a number a tired person would read as
 * "looks fine" belongs in a script with a threshold and an exit code.
 *
 *   php artisan queue:check-depth
 *   php artisan queue:check-depth --warn=20 --critical=100
 *   php artisan queue:check-depth --json
 *
 * Bucket semantics, read straight off the `jobs` row:
 *
 *   - `pending`: available now (`available_at <= now`) and unclaimed
 *     (`reserved_at` null). This is the number that means RSVP lag — every
 *     waiting job is an event Discord disagrees with us about.
 *   - `delayed`: not yet available. Fresh write-backs sit here for their
 *     10-second debounce, so a nonzero delayed count with zero pending is a
 *     healthy burst of answers, not a backlog.
 *   - `reserved`: claimed by a worker. Briefly nonzero during normal work; a
 *     large stuck value means a dead worker holding its claim, not a backlog.
 *   - `failed`: rows in `failed_jobs`. Reported, never thresholded: a failure
 *     already logged at dispatch time and the reconcile pass owns recovery.
 *
 * Threshold semantics: `ok` below warn, `warn` at or above warn, `critical`
 * at or above critical. Defaults are 20/100. Write-backs are unique per event
 * key, so the pending count is roughly the number of events unmirrored: 20 is
 * staleness a member can see across the calendar, and 100 is past what a live
 * worker drains between ten-minute reconcile passes, which points at the
 * worker being down rather than busy. Tune per box; the flags exist for that.
 *
 * This probes the database driver only. Any other driver has no countable
 * depth, so the command says so and exits 1 rather than reporting a zero that
 * would read as healthy.
 */
class CheckQueueDepth extends Command
{
    protected $signature = 'queue:check-depth
                            {--warn=20 : Pending-job count at or above which the status becomes warn.}
                            {--critical=100 : Pending-job count at or above which the status becomes critical.}
                            {--json : Emit the findings as JSON instead of a table.}';

    protected $description = 'Report pending-job depth of the database queue (TOG-6773)';

    public function handle(): int
    {
        $connection = (string) config('queue.default', 'database');
        $driver = config("queue.connections.{$connection}.driver");

        if ($driver !== 'database') {
            return $this->report([
                'status' => 'error',
                'connection' => $connection,
                'driver' => $driver,
                'pending' => null,
                'detail' => "queue driver '{$driver}' has no countable depth; this probe covers the database queue only.",
            ], self::FAILURE);
        }

        $warn = $this->threshold('warn');
        $critical = $this->threshold('critical');

        if ($warn === null || $critical === null || $warn < 0 || $critical < 0 || $warn > $critical) {
            return $this->report([
                'status' => 'error',
                'connection' => $connection,
                'driver' => $driver,
                'pending' => null,
                'detail' => "--warn and --critical must be non-negative integers with warn <= critical; got warn='{$this->option('warn')}', critical='{$this->option('critical')}'.",
            ], self::FAILURE);
        }

        try {
            $depth = $this->measure();
        } catch (QueryException $e) {
            return $this->report([
                'status' => 'error',
                'connection' => $connection,
                'driver' => $driver,
                'pending' => null,
                'detail' => 'could not read the queue table: '.$e->getMessage(),
            ], self::FAILURE);
        }

        $status = $depth['pending'] >= $critical ? 'critical'
            : ($depth['pending'] >= $warn ? 'warn' : 'ok');

        return $this->report($depth + [
            'status' => $status,
            'connection' => $connection,
            'driver' => $driver,
            'warn_at' => $warn,
            'critical_at' => $critical,
        ], $status === 'ok' ? self::SUCCESS : self::FAILURE);
    }

    /**
     * The pending count is the oldest wait in disguise: write-backs are unique
     * per event key, so each pending row is one event Discord disagrees with
     * us about, and the oldest `created_at` among them is how long the
     * longest-waiting answer has gone unmirrored.
     *
     * @return array{pending: int, delayed: int, reserved: int, total: int, failed: int, oldest_pending_age_seconds: ?int}
     */
    private function measure(): array
    {
        // The queue's own connection/table, not the default: DB_QUEUE_CONNECTION
        // and DB_QUEUE_TABLE exist precisely so the queue can live on its own
        // database, and counting the wrong table's rows would be worse than
        // counting nothing.
        $db = DB::connection(config('queue.connections.database.connection'));
        $jobs = $db->table(config('queue.connections.database.table', 'jobs'));
        $now = time();

        $pending = (clone $jobs)->where('available_at', '<=', $now)->whereNull('reserved_at');
        $oldest = $pending->clone()->min('created_at');

        return [
            'pending' => $pending->clone()->count(),
            'delayed' => (clone $jobs)->where('available_at', '>', $now)->count(),
            'reserved' => (clone $jobs)->whereNotNull('reserved_at')->count(),
            'total' => (clone $jobs)->count(),
            'failed' => $db->table(config('queue.failed.table', 'failed_jobs'))->count(),
            'oldest_pending_age_seconds' => $oldest === null ? null : $now - (int) $oldest,
        ];
    }

    private function threshold(string $name): ?int
    {
        $raw = $this->option($name);

        return is_numeric($raw) && (int) $raw == $raw ? (int) $raw : null;
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
            $this->line(sprintf('  %-28s %s', $key, $shown));
        }

        return $exit;
    }
}
