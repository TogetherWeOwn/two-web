<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\DuskTestCase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Dusk runs through phpunit.dusk.xml against a real browser and a real server, so
// it is never part of `composer test`. See `composer test:e2e`.
pest()->extend(DuskTestCase::class)->in('Browser');
