<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Pending-job depth of the Postgres queue, for every surface that reports it
 * ([TOG-8414](/TOG/issues/TOG-8414)).
 *
 * Sessions, cache and queue all run on Postgres, and the RSVP write-back to
 * Discord is async: the row is committed, the job is dispatched after the
 * commit, and the member's answer sits at `synced_to_discord_at = null` until
 * a worker picks the job up. A silent queue backlog is therefore silent RSVP
 * delay — no 500, no log line, the Discord mirror just stops catching up.
 *
 * Two readers share this counting, and they must never disagree about what
 * "deep" means, so there is exactly one implementation:
 *
 *   - `queue:check-depth` — the box-side probe with tunable warn/critical
 *     thresholds and exit codes;
 *   - `GET /up` — the health endpoint the deploy poll reads, which reports
 *     `degraded` past the warn threshold and never `down` for a deep queue.
 *     A backlog is not an outage, and the deploy poll (`curl -f` in
 *     `.github/workflows/deploy.yml`) must keep passing through one.
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
 * Threshold semantics: `ok` below warn, `warn`/`degraded` at or above warn,
 * `critical` at or above critical. Defaults are 20/100. Write-backs are unique
 * per event key, so the pending count is roughly the number of events
 * unmirrored: 20 is staleness a member can see across the calendar, and 100 is
 * past what a live worker drains between ten-minute reconcile passes, which
 * points at the worker being down rather than busy. Tune per box in the
 * command flags; `/up` always quotes the defaults below.
 *
 * This probes the database driver only. Any other driver has no countable
 * depth, so readers must say so rather than reporting a zero that would read
 * as healthy.
 */
final class QueueHealth
{
    /**
     * Pending jobs at or above this count read as backlog, not burst: roughly
     * the number of events Discord disagrees with us about that a member can
     * see as staleness across the calendar.
     */
    public const WARN_AT = 20;

    /**
     * Pending jobs at or above this count point at the worker being down
     * rather than busy: past what a live worker drains between ten-minute
     * reconcile passes.
     */
    public const CRITICAL_AT = 100;

    /**
     * The configured default queue connection name (for example `database`).
     */
    public static function connectionName(): string
    {
        return (string) config('queue.default', 'database');
    }

    /**
     * The driver behind the default connection, or null when unconfigured.
     */
    public static function driver(): ?string
    {
        $connection = self::connectionName();

        $driver = config("queue.connections.{$connection}.driver");

        return is_string($driver) ? $driver : null;
    }

    /**
     * Whether the default connection has a countable depth. Only the
     * database driver does; anything else (sync, null, failover onto
     * non-database drivers) has no `jobs` table to count.
     */
    public static function isCountable(): bool
    {
        return self::driver() === 'database';
    }

    /**
     * The pending count is the oldest wait in disguise: write-backs are unique
     * per event key, so each pending row is one event Discord disagrees with
     * us about, and the oldest `created_at` among them is how long the
     * longest-waiting answer has gone unmirrored.
     *
     * @return array{pending: int, delayed: int, reserved: int, total: int, failed: int, oldest_pending_age_seconds: ?int}
     *
     * @throws QueryException when the queue table cannot be read.
     */
    public static function measure(): array
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
}
