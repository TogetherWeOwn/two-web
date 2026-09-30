<?php

// The suite wipes and re-migrates whatever database it points at, so the guard
// in tests/Support/TestDatabaseGuard.php must refuse everything that is not a
// disposable test database — before the first migration runs (TOG-9649).
//
// These are pure unit tests: the guard takes plain strings and throws, so no
// database connection is needed to prove each branch.

use Tests\Support\TestDatabaseGuard;
use Tests\Support\TestDatabaseRefusedException;

it('allows the local and CI test database', function () {
    expect(fn () => TestDatabaseGuard::check('pgsql', 'two_web_test', 'two_web'))->not->toThrow(Exception::class);
});

it('allows suffixed per-worktree test databases', function () {
    expect(fn () => TestDatabaseGuard::check('pgsql', 'two_web_test_tog7330', 'two_web'))->not->toThrow(Exception::class);
});

it('refuses the development database', function () {
    TestDatabaseGuard::check('pgsql', 'two_web', 'two_web');
})->throws(TestDatabaseRefusedException::class, 'two_web_test');

it('refuses the production controller database by name', function () {
    TestDatabaseGuard::check('pgsql', 'paperclip', 'two_web');
})->throws(TestDatabaseRefusedException::class, 'production controller');

it('refuses the production controller database even under a test database name', function () {
    // A renamed controller database must not slip through on the name check
    // alone: the owning role is identified too.
    TestDatabaseGuard::check('pgsql', 'two_web_test', 'paperclip_app');
})->throws(TestDatabaseRefusedException::class, 'paperclip_app');

it('refuses a controller role login on the test database', function () {
    TestDatabaseGuard::check('pgsql', 'two_web_test', 'paperclip');
})->throws(TestDatabaseRefusedException::class, 'paperclip');

it('refuses an empty database name', function () {
    TestDatabaseGuard::check('pgsql', '', 'two_web');
})->throws(TestDatabaseRefusedException::class);

it('refuses a database that merely contains the test prefix', function () {
    TestDatabaseGuard::check('pgsql', 'not_two_web_test', 'two_web');
})->throws(TestDatabaseRefusedException::class);
