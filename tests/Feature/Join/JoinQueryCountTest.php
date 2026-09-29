<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Query-count pin for the public join page, mirroring EventQueryCountTest.
// `JoinController::show` renders static copy plus config values — no model
// reads — so the page must stay flat no matter how many user rows exist. Each
// test seeds enough users that a future per-row query (a member count, a
// recent-joins list) would blow the bound, then asserts the total stays flat.
// Bounds are deliberately loose (a handful of queries, not zero): the thing
// being pinned is "does not grow with the row count", not today's exact
// query plan.
//
// Self-contained on purpose: this file uses only the join page, the session
// banner and the user factory, so it passes on a clean tree with no other
// join-table work present.

it('renders the join page with a bounded number of queries no matter how many users exist', function () {
    User::factory()->count(10)->create();

    DB::enableQueryLog();
    $this->get(route('join'))->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Ten users. A per-row query would put this past ten.
    expect(count($queries))->toBeLessThan(5);
});

it('renders the join result banner with a bounded number of queries', function () {
    User::factory()->count(10)->create();

    DB::enableQueryLog();
    $this->withSession(['join_result' => 'added'])
        ->get(route('join'))
        ->assertOk()
        ->assertSeeHtml('data-testid="join-result"');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count($queries))->toBeLessThan(5);
});
