<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\DuskTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Tests that need transactions which really commit, so they truncate instead of
// wrapping. RefreshDatabase holds every test inside a transaction it never commits,
// and two things here depend on that not being true: the capacity race is fought by
// separate OS processes, which cannot see rows in somebody else's uncommitted
// transaction; and a job dispatched `afterCommit` only runs when a commit actually
// happens. Its own directory rather than a per-file trait override, so the
// difference is visible from the file tree and nothing else inherits it by accident.
//
// Truncation runs after each test as well as before it. The trait only truncates
// in setUp, so the last test to run leaves its committed rows behind — and the
// next RefreshDatabase test in the same process inherits them, because a rollback
// only undoes that test's own transaction. That is 7 red calendar tests whenever
// Integration runs before Feature in one process (TOG-5620).
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->afterEach(function (): void {
        $this->truncateDatabaseTables();
    })
    ->in('Integration');

// Dusk runs through phpunit.dusk.xml against a real browser and a real server, so
// it is never part of `composer test`. See `composer test:e2e`.
//
// DatabaseTruncation, not RefreshDatabase, and it is not optional. The browser and
// the server are separate processes, so rows wrapped in the test's own uncommitted
// transaction are invisible to the page under test — RefreshDatabase would hide
// every fixture from the browser that is supposed to see it.
//
// Truncating is what makes a Dusk test able to assert an *absence*. Until this was
// here the suite shared one database with no reset between tests, and that was
// survivable only because the tests that existed created users and asserted on
// what they could see. The first test to assert "there are no events" inherited the
// events three earlier tests had created and failed in CI while passing alone.
pest()->extend(DuskTestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Browser');
