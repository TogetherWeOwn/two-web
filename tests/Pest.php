<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\DuskTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// The capacity race is fought by separate OS processes on their own connections,
// and those cannot see rows sitting inside a test's uncommitted transaction. This
// suite therefore commits and truncates instead of wrapping. It is its own
// directory rather than a per-file trait override so the difference is visible
// from the file tree, and so nothing else accidentally inherits it.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

// Dusk runs through phpunit.dusk.xml against a real browser and a real server, so
// it is never part of `composer test`. See `composer test:e2e`.
pest()->extend(DuskTestCase::class)->in('Browser');
