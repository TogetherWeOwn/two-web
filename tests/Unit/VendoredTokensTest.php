<?php

// The design system is vendored from two-design (TWO-19). DesignSystemTest already
// guards the three ways the *integration* drifts — a missing font file, a
// font-family declared outside two.css, a layout that lost the preload.
//
// This guards the thing none of those can see: somebody editing the vendored copy.
//
// Nothing in this repo checks colour contrast. two-design does, on 43 pairings,
// against WCAG 2.2 AA, on its own CI — and that guarantee reaches the site only
// while our copy is byte-identical to theirs. Nudge a hex in resources/css/two.css
// and the whole suite still passes while the page is less accessible than the
// system says it is. So the copy is checked by digest, and refreshing it is a
// deliberate two-line commit rather than something that happens by accident.
//
// If this fails: do not update the digest to make it pass. Find out whether the
// file was edited here (restore it) or genuinely refreshed from two-design (then
// yes, update it — and say which two-design commit in the PR body).

$manifest = 'vendored.design-system';

it('has a manifest of every vendored file', function () use ($manifest) {
    expect(base_path($manifest))->toBeFile(
        "{$manifest} is missing. It is the only record of which two-design commit this repo is running."
    );
});

it('has not had the vendored design system edited in place', function () use ($manifest) {
    $raw = file_get_contents(base_path($manifest));

    if ($raw === false) {
        throw new RuntimeException("{$manifest} exists but could not be read.");
    }

    $lines = preg_split('/\R/', $raw) ?: [];

    $expected = [];
    $upstream = null;

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }

        if (preg_match('/^upstream\s+(.+)$/', trim($line), $match)) {
            $upstream = $match[1];

            continue;
        }

        // sha256sum's own output format, so `sha256sum -c` works on this file too.
        if (preg_match('/^([0-9a-f]{64})\s+(\S+)$/', trim($line), $match)) {
            $expected[$match[2]] = $match[1];
        }
    }

    expect($upstream)->not->toBeNull("{$manifest} does not record which two-design commit it came from.");
    expect($expected)->not->toBeEmpty("{$manifest} lists no files — an empty manifest checks nothing.");

    $drifted = [];

    foreach ($expected as $path => $digest) {
        $full = base_path($path);

        if (! is_file($full)) {
            $drifted[] = "{$path} — listed in {$manifest} but not in the repo";

            continue;
        }

        $actual = hash_file('sha256', $full);

        if ($actual !== $digest) {
            $drifted[] = "{$path}\n      manifest: {$digest}\n      on disk:  {$actual}";
        }
    }

    expect($drifted)->toBe([], implode("\n", [
        "The vendored design system does not match {$manifest} (upstream: {$upstream}).",
        implode("\n", $drifted),
        'If you edited one of these here, restore it — the source of truth is two-design,',
        'and its WCAG 2.2 AA contrast gate only covers this repo while the bytes match.',
        'If you deliberately refreshed from two-design, update the digest and the upstream',
        'commit in the same commit, and name the two-design commit in the PR body.',
    ]));
});
