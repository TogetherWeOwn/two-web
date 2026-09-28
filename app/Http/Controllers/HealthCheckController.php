<?php

namespace App\Http\Controllers;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `/up` — the deploy and CI health probe (TOG-8711).
 *
 * Laravel's built-in health route (`health: '/up'` in `bootstrap/app.php`)
 * only fires the `DiagnosingHealth` event and renders a fixed HTML page: it
 * answers 200 while the database is unreachable or migrations are pending, so
 * a deploy with a failed migrate looks healthy. This controller replaces that
 * route (see `routes/health.php`) with a structured signal:
 *
 *   - healthy: 200 `{"status":"ok","db":"ok","pending_migrations":0}`
 *   - database unreachable: 503 `{"status":"error","db":"error","pending_migrations":null}`
 *   - migrations pending: 503 `{"status":"error","db":"ok","pending_migrations":N}`
 *
 * Pending migrations are a 503 on purpose, not a 200 with a count: the deploy
 * pipeline polls `/up` with `curl -fsS` (`Wait for staging to answer` in
 * `.github/workflows/deploy.yml`, after `php artisan migrate --force` in
 * `docs/runbook.md`), so anything but 200 fails the deploy and points at
 * rollback. A failed migrate must never read as a healthy release.
 *
 * The pending count mirrors `migrate:status`: migration files on disk (the
 * default path plus any `$migrator->paths()`) minus the names recorded in the
 * `migrations` table. A database that has never migrated has no repository
 * table, so every file counts as pending rather than erroring — a fresh box
 * that skipped the migrate step reads as not-ready, which is what it is.
 *
 * Failure bodies carry no exception text: the why goes to the logs via
 * `report()`, the wire carries only the signal. The DB ping is a bare
 * `getPdo()` — opening the connection is the whole check.
 */
final class HealthCheckController
{
    public function __invoke(Migrator $migrator): JsonResponse
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => 'error',
                'db' => 'error',
                'pending_migrations' => null,
            ], 503);
        }

        $pending = $this->pendingMigrationCount($migrator);

        if ($pending > 0) {
            return response()->json([
                'status' => 'error',
                'db' => 'ok',
                'pending_migrations' => $pending,
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'db' => 'ok',
            'pending_migrations' => 0,
        ]);
    }

    private function pendingMigrationCount(Migrator $migrator): int
    {
        $files = $migrator->getMigrationFiles(
            array_merge($migrator->paths(), [database_path('migrations')])
        );

        $ran = $migrator->repositoryExists()
            ? $migrator->getRepository()->getRan()
            : [];

        return count(array_diff(array_keys($files), $ran));
    }
}
