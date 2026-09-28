<?php

// The no-build half of the Vite bundle budget (TOG-5629).
//
// ci/bundle-budget.json caps every built entrypoint, and
// ci/check-bundle-budget.mjs enforces it in the `budgets` job after
// `npm run build`. That checker needs a manifest, so it cannot run in Pest —
// the same split as ViteEntrypointsTest, which asserts the entrypoints without
// building. This file asserts the budget without a build: it names exactly the
// inputs vite will build, every ceiling is a positive number, and every source
// file is on disk. The byte comparison itself lives in the checker, whose own
// `--selftest` runs in `static` so a checker that stops checking goes red
// there; `--lint` (check 12) pins the exact ceilings and the CI wiring.

/** The `budgets` object from ci/bundle-budget.json, parsed. */
function bundleBudgetCeilings(): array
{
    $path = base_path('ci/bundle-budget.json');

    expect(is_file($path))->toBeTrue('ci/bundle-budget.json is missing — the `budgets` job has no bundle thresholds to enforce.');

    $decoded = json_decode((string) file_get_contents($path), true);

    expect($decoded)->toBeArray('ci/bundle-budget.json is not valid JSON.');
    expect($decoded['budgets'] ?? null)->toBeArray('ci/bundle-budget.json has no `budgets` object.');

    return $decoded['budgets'];
}

/** The `input:` list from vite.config.js — what a build will actually produce. */
function bundleBudgetViteInputs(): array
{
    $config = (string) file_get_contents(base_path('vite.config.js'));

    expect(preg_match('/input:\s*\[(.*?)\]/s', $config, $match))->toBe(1, 'vite.config.js has no `input:` list — nothing would be built at all.');

    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $match[1], $entries);

    return array_values(array_unique($entries[1]));
}

it('budgets every vite input and nothing else', function () {
    $budgets = bundleBudgetCeilings();
    $inputs = bundleBudgetViteInputs();

    // Without this, deleting every ceiling passes by checking nothing — the
    // same vacuity ViteEntrypointsTest guards against on the inputs side.
    expect($budgets)->not->toBe([], 'ci/bundle-budget.json budgets nothing. Either the app genuinely has no entrypoints, or the budget was emptied to make a breach go green — check the file before assuming the former.');

    $unbudgeted = array_values(array_diff($inputs, array_keys($budgets)));

    expect($unbudgeted)->toBe([], "vite input with no bundle budget — it can grow without bound while CI stays green:\n".implode("\n", $unbudgeted));

    $stale = array_values(array_diff(array_keys($budgets), $inputs));

    expect($stale)->toBe([], "bundle budget for something vite no longer builds. A stale ceiling is dead weight today and a lie tomorrow — remove it:\n".implode("\n", $stale));
});

it('holds positive ceilings over source files that exist', function () {
    foreach (bundleBudgetCeilings() as $entry => $ceiling) {
        expect(is_int($ceiling['maxRawBytes'] ?? null) && ($ceiling['maxRawBytes'] ?? 0) > 0)
            ->toBeTrue("budget for {$entry} has no positive integer `maxRawBytes` — a missing or zero ceiling is no ceiling.");

        expect(is_int($ceiling['maxGzipBytes'] ?? null) && ($ceiling['maxGzipBytes'] ?? 0) > 0)
            ->toBeTrue("budget for {$entry} has no positive integer `maxGzipBytes` — the Slow 4G profile pays gzip bytes, so an unmeasurable gzip ceiling is the budget that matters, missing.");

        expect(is_file(base_path($entry)))->toBeTrue("budget for {$entry}, but that source file is not on disk — the ceiling guards nothing.");
    }
});
