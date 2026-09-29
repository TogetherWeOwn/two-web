<?php

use App\Enums\JoinOutcome;
use App\Models\JoinAttempt;
use Illuminate\Support\Facades\DB;

// TOG-8731: join_attempts grows one row per join attempt. The outcome-only
// index cannot prune by time, so the retention prune and any time-ranged
// admin query table-scan as volume grows. These tests pin the composite
// (created_at, outcome) index and prove the planner reaches for it on both
// query shapes via EXPLAIN.
function joinAttemptIndexPlan(string $sql, array $bindings): string
{
    // Small fixture tables invite a seq scan no matter what indexes exist,
    // so take that plan off the table: the assertion is that the composite
    // index is a usable access path for the query shape, not that it wins
    // a cost contest on ten rows.
    DB::statement('SET LOCAL enable_seqscan = off');

    return collect(DB::select("EXPLAIN {$sql}", $bindings))
        ->pluck('QUERY PLAN')
        ->implode("\n");
}

it('has the composite (created_at, outcome) index on join_attempts', function () {
    $index = DB::selectOne(
        "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'join_attempts' AND indexname = 'join_attempts_created_at_outcome_index'"
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('created_at')
        ->and($index->indexdef)->toContain('outcome');
});

it('uses the composite index for the retention-prune shape', function () {
    foreach (JoinOutcome::cases() as $i => $outcome) {
        JoinAttempt::query()->create(['outcome' => $outcome]);
    }

    // Same shape as JoinAttempt::prunable(): created_at < ?.
    $plan = joinAttemptIndexPlan(
        'SELECT * FROM join_attempts WHERE created_at < ?',
        [now()->subDays(90)]
    );

    expect($plan)->toContain('join_attempts_created_at_outcome_index');
});

it('uses the composite index for a time-ranged per-outcome count', function () {
    foreach (JoinOutcome::cases() as $outcome) {
        JoinAttempt::query()->create(['outcome' => $outcome]);
    }

    // Time-ranged admin/funnel shape: bound the range on the leading column,
    // group on the second.
    $plan = joinAttemptIndexPlan(
        'SELECT outcome, count(*) FROM join_attempts WHERE created_at >= ? GROUP BY outcome',
        [now()->subDays(90)]
    );

    expect($plan)->toContain('join_attempts_created_at_outcome_index');
});
