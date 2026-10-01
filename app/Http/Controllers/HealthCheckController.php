<?php

namespace App\Http\Controllers;

use App\Support\QueueHealth;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `GET /up` — the deploy and uptime signal, with the queue depth folded in
 * ([TOG-8414](/TOG/issues/TOG-8414)) and the database readiness folded in
 * ([TOG-8711](/TOG/issues/TOG-8711)).
 *
 * Laravel's built-in `/up` (bootstrap/app.php `health: '/up'`) answers the
 * wrong question here: it only knows healthy (200) versus booting an exception
 * (500). A silent queue backlog is silent RSVP delay — no 500, no log line,
 * the Discord mirror just stops catching up — so `/up` said "up" through the
 * one outage shape members actually feel. This controller keeps the framework
 * route for the two shapes it owns (boot failure, maintenance) and answers
 * the third one the box probe (`queue:check-depth`) already knew how to see.
 *
 * The contract the deploy poll and the monitors rely on:
 *
 *   - healthy, or degraded by the queue alone, answers 200. A backlog is not
 *     an outage, and the deploy poll (`curl -f` in
 *     `.github/workflows/deploy.yml`) must keep passing through one — `/up`
 *     distinguishes queue shapes in the body, not the status code.
 *   - degraded by the database or the schema answers 503. An unreachable
 *     database, a migration repository that will not answer, or pending
 *     migrations all fail the deploy poll instead of shipping a not-ready
 *     box. The queue slice still rides along, so the failure stays
 *     diagnosable; `status` stays `degraded`, never down.
 *   - `status` is one of `healthy`, `degraded`. `degraded` at or above the
 *     warn threshold (QueueHealth::WARN_AT pending jobs), still `degraded` —
 *     never down — past critical. The queue numbers always ride along so a
 *     dashboard reads the lag without a second probe.
 *   - `db` is `ok` when a read-only query on the default connection answers,
 *     `error` when it does not. `pending_migrations` counts migration files
 *     minus ran migrations (mirroring `migrate:status`), `null` when neither
 *     the query nor the repository would answer.
 *   - the queue read can never sink the endpoint. A driver with no countable
 *     depth, or a queue table that will not answer, is reported in the body
 *     (`queue.status: unknown`) and the endpoint stays on the last known
 *     application health. An `/up` that 500s because the queue is slow
 *     mistakes the smoke detector for the fire.
 *
 * Placement: `routes/funnel.php`, with the deliberately empty middleware
 * stack — not `routes/web.php`. Two reasons. First, `SESSION_DRIVER=database`
 * everywhere we ship, so a `web` route opens Postgres in StartSession before
 * the controller runs, and today's `/up` answers 200 through an app-DB outage
 * (the framework route never touches the database); moving the probe into
 * `web` would turn a DB blip into a failed deploy poll. Here the queue read
 * fails into `unknown` and the endpoint still answers. Second, monitors poll
 * this with no session, no CSRF state and nothing to abuse, so there is
 * nothing for the `web` stack to add and no throttle to put in front of it.
 * The route URI is pinned in `tests/Unit/ProductionRouteAllowlistTest.php`
 * (`GET|HEAD up`); moving it elsewhere renames the only health check the
 * deploy knows.
 */
class HealthCheckController
{
    public function __invoke(): JsonResponse
    {
        $queue = $this->queueHealth();
        $readiness = $this->databaseReadiness();

        // Readiness gates the status code, the queue never does: a deep or
        // unreadable queue stays 200 (backlog is lag, not an outage), while
        // an unreachable database or an unmigrated schema answers 503 so the
        // deploy poll (`curl -f`) fails instead of shipping a not-ready box.
        if ($readiness['db'] !== 'ok' || $readiness['pending_migrations'] !== 0) {
            return response()->json([
                'status' => 'degraded',
                'db' => $readiness['db'],
                'pending_migrations' => $readiness['pending_migrations'],
                'queue' => $queue,
            ], 503);
        }

        $status = $queue['status'] === 'degraded' ? 'degraded' : 'healthy';

        return response()->json([
            'status' => $status,
            'db' => 'ok',
            'pending_migrations' => 0,
            'queue' => $queue,
        ], 200);
    }

    /**
     * The database slice of the payload. Never throws: every probe failure
     * is a reported `error`/`null`, never a 500.
     *
     * The ping is a real read-only query on the default connection, not a
     * cached PDO handle: a pooled handle can stay open while the database
     * is gone. The pending count mirrors `migrate:status` — migration file
     * names from the registered paths plus `database/migrations`, minus the
     * names in the migration repository. A missing repository table means
     * nothing has run, so every file is pending. A repository that will not
     * answer is distinct from a database that will not answer: `db` stays
     * `ok` with `pending_migrations: null`, still 503, never an unhandled
     * 500. A missing or unreadable migration directory is unknown schema
     * state, not proof the schema is current: discovery is globbing, so a
     * missing path yields zero files and would otherwise read as healthy —
     * it answers the same `null`-count 503. Nothing thrown or read here
     * reaches the wire — failures are logged server-side, best-effort, with
     * the exception class only, never the message.
     *
     * @return array{db: string, pending_migrations: ?int}
     */
    private function databaseReadiness(): array
    {
        try {
            DB::connection()->select('select 1');
        } catch (Throwable $e) {
            $this->safeHealthLog('Health check could not reach the database; reporting degraded.', $e);

            return ['db' => 'error', 'pending_migrations' => null];
        }

        try {
            /** @var Migrator $migrator */
            $migrator = app(Migrator::class);

            $paths = array_merge($migrator->paths(), [database_path('migrations')]);

            // Discovery is globbing: a missing or unreadable required path
            // yields zero files, which would read as "current". Treat that
            // as unknown, not healthy. An existing-but-empty directory stays
            // valid — zero files is evidence, a missing path is not.
            foreach ($paths as $path) {
                $readable = str_ends_with($path, '.php')
                    ? is_readable($path)
                    : (is_dir($path) && is_readable($path));

                if (! $readable) {
                    $this->safeHealthLog('Health check could not read the migration files; reporting degraded.');

                    return ['db' => 'ok', 'pending_migrations' => null];
                }
            }

            $files = $migrator->getMigrationFiles($paths);

            if (! $migrator->repositoryExists()) {
                return ['db' => 'ok', 'pending_migrations' => count($files)];
            }

            $ran = $migrator->getRepository()->getRan();

            return [
                'db' => 'ok',
                'pending_migrations' => count(array_diff(array_keys($files), $ran)),
            ];
        } catch (Throwable $e) {
            $this->safeHealthLog('Health check could not read the migration repository; reporting degraded.', $e);

            return ['db' => 'ok', 'pending_migrations' => null];
        }
    }

    /**
     * Best-effort, non-throwing, class-only diagnostic logging for the probe.
     *
     * The endpoint promises a JSON 503 on probe failure, never a 500 — so
     * the diagnostics themselves must not be able to sink it. Neither
     * `report()` nor `Log::warning()` carries a non-throwing guarantee (the
     * logging stack runs with `ignore_exceptions: false`), and `report()`
     * forwards the raw exception message to the error logger, contradicting
     * the class-only contract. This wrapper logs the message with the
     * exception class only, and swallows any logger failure so the degraded
     * envelope is always returned.
     */
    private function safeHealthLog(string $message, ?Throwable $e = null): void
    {
        try {
            Log::warning($message, $e === null ? [] : [
                'exception' => $e::class,
            ]);
        } catch (Throwable) {
            // Diagnostics must never sink the probe.
        }
    }

    /**
     * The queue slice of the payload. Never throws: every probe failure is a
     * reported `unknown`, never a 500.
     *
     * @return array{status: string, pending: ?int, delayed: ?int, reserved: ?int, total: ?int, failed: ?int, oldest_pending_age_seconds: ?int, warn_at: int, critical_at: int, detail: ?string}
     */
    private function queueHealth(): array
    {
        $driver = QueueHealth::driver();

        if (! QueueHealth::isCountable()) {
            return $this->unknownQueue("queue driver '".($driver ?? 'unconfigured')."' has no countable depth.");
        }

        try {
            // Single shared counting with the box probe (CheckQueueDepth via
            // QueueHealth::measure): the two readers must never disagree
            // about what "deep" means.
            $depth = QueueHealth::measure();
        } catch (Throwable $e) {
            // Class only, never the message: the queue tables carry job
            // payloads, and the endpoint must not leak one into a log.
            // Best-effort through the shared helper: the diagnostics must
            // not sink the endpoint the way a throwing catch would.
            $this->safeHealthLog('Health check could not read the queue depth; reporting unknown.', $e);

            return $this->unknownQueue(null);
        }

        // Degraded, not down, when deep: a backlog is RSVP lag, not an
        // outage, and the deploy poll must keep passing through one.
        return [
            'status' => $depth['pending'] >= QueueHealth::WARN_AT ? 'degraded' : 'healthy',
            'pending' => $depth['pending'],
            'delayed' => $depth['delayed'],
            'reserved' => $depth['reserved'],
            'total' => $depth['total'],
            'failed' => $depth['failed'],
            'oldest_pending_age_seconds' => $depth['oldest_pending_age_seconds'],
            'warn_at' => QueueHealth::WARN_AT,
            'critical_at' => QueueHealth::CRITICAL_AT,
            'detail' => null,
        ];
    }

    /**
     * @return array{status: string, pending: ?int, delayed: ?int, reserved: ?int, total: ?int, failed: ?int, oldest_pending_age_seconds: ?int, warn_at: int, critical_at: int, detail: ?string}
     */
    private function unknownQueue(?string $detail): array
    {
        return [
            'status' => 'unknown',
            'pending' => null,
            'delayed' => null,
            'reserved' => null,
            'total' => null,
            'failed' => null,
            'oldest_pending_age_seconds' => null,
            'warn_at' => QueueHealth::WARN_AT,
            'critical_at' => QueueHealth::CRITICAL_AT,
            'detail' => $detail,
        ];
    }
}
