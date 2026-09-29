<?php

namespace Tests\Support;

/**
 * The last line of defence between `RefreshDatabase` and the wrong database (TOG-9649).
 *
 * On 2026-09-29 a `php artisan test` run hit ownership errors on `two_web_test`,
 * picked up a `DATABASE_URL` inherited from the worker environment, and ran
 * `migrate:fresh` against the production controller database. Two layers stop a
 * repeat:
 *
 *   1. `phpunit.xml` forces `DB_CONNECTION` and `DB_DATABASE` with `force="true"`,
 *      so the environment cannot repoint the suite at another database.
 *   2. This guard, called from `Tests\TestCase::setUpTraits()` before
 *      `RefreshDatabase` / `DatabaseTruncation` run their first migration, throws
 *      unless the resolved default connection points at a disposable test database.
 *
 * The guard inspects the resolved config, not the raw environment: by the time
 * `setUpTraits()` runs the application is booted, so `.env`, real environment
 * variables and `phpunit.xml` have all been merged and this sees the database the
 * suite would actually migrate.
 *
 * What counts as disposable:
 *
 *   - Refuse outright when the database or username identifies the production
 *     controller (`paperclip` / `paperclip_app`). Those never appear in a
 *     legitimate test run, whatever the host, so they are refused with a message
 *     that names them — a generic "wrong database" error is how the next incident
 *     gets debugged for an hour.
 *   - Otherwise require the `two_web_test` prefix. Plain `two_web_test` locally
 *     and in CI, suffixed per-worktree names (`two_web_test_tog7330`) elsewhere.
 *
 * Host and port are deliberately NOT checked. The documented sandbox flow points
 * `.env` at a shared Postgres the developer is allowed to wipe, and that server
 * legitimately hosts both `two_web` and `two_web_test` — refusing its host would
 * break that flow, while the name check already protects the data on it.
 *
 * There is deliberately no escape hatch. A `TEST_ALLOW_ANY_DB=1` seam would be
 * exactly the mechanism a future misconfigured worker inherits, and the suite has
 * no legitimate reason to touch a non-test database — Dusk, the one suite that
 * writes to a real database, does not come through `Tests\TestCase`.
 */
final class TestDatabaseGuard
{
    /**
     * Database names that are never a test database, whatever the host.
     *
     * The production controller database and the roles that own it, from the
     * 2026-09-29 wipe (TOG-9646). Matched exactly, not by prefix: `paperclip`
     * must never collide with a future `paperclip_something_test`.
     */
    private const CONTROLLER_DATABASES = ['paperclip'];

    private const CONTROLLER_USERS = ['paperclip', 'paperclip_app'];

    public static function check(string $connection, string $database, ?string $username = null): void
    {
        if (in_array($database, self::CONTROLLER_DATABASES, true)) {
            throw new TestDatabaseRefusedException(
                "Refusing to run tests against the '{$database}' database: it is the production controller database, not a test database. ".
                'Check DB_DATABASE in your .env — the suite only ever touches two_web_test* (TOG-9649).'
            );
        }

        if (is_string($username) && in_array($username, self::CONTROLLER_USERS, true)) {
            throw new TestDatabaseRefusedException(
                "Refusing to run tests as the '{$username}' role: it owns the production controller database, not test data. ".
                'Check DB_USERNAME in your .env (TOG-9649).'
            );
        }

        if (! str_starts_with($database, 'two_web_test')) {
            throw new TestDatabaseRefusedException(
                "Refusing to run tests against the '{$database}' database on connection '{$connection}': ".
                'the suite wipes and re-migrates whatever it points at, so it only runs against two_web_test* databases. '.
                'Set DB_DATABASE=two_web_test in your .env (TOG-9649).'
            );
        }
    }
}
