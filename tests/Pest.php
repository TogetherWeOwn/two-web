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
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Integration');

// Dusk runs through phpunit.dusk.xml against a real browser and a real server, so
// it is never part of `composer test`. See `composer test:e2e`.
pest()->extend(DuskTestCase::class)->in('Browser');
