<?php

// TOG-8417: the join flow's member-facing copy lives in `lang/en/join.php` and
// nowhere else. JoinController already renders through `__()`; the last
// hardcoded string was the page `<title>` in join.blade.php. This file pins
// the contract from both sides so a rename, a rewording, or a new hardcoded
// sentence goes red here instead of shipping a raw key or silent copy drift:
//
// 1. The lang file holds exactly the known key set — no missing key (which
//    `__()` would leak as a raw `join.…` string to members) and no stray key
//    (which means copy nobody renders and nobody reviews).
// 2. Every `join.…` reference in the controller and the three blades that
//    render join output resolves, including the dynamic `join.result.*` flash.
// 3. No hardcoded English sentence remains in JoinController's executable
//    code. Log lines and exception messages stay literals on purpose (they are
//    dev-facing, never rendered); everything else with a space in it fails.
//
// NOTE: helper names are file-prefixed. Pest loads every test file into one
// process, so a generic name would fatal on redeclaration (see
// JoinDenialMatrixTest).

/** Every leaf key `lang/en/join.php` is expected to hold. */
function joinLangExpectedKeys(): array
{
    return [
        'heading',
        'intro',
        'one_click',
        'invite',
        'recovery_title',
        'recovery_denied',
        'recovery_error',
        'recovery_retry',
        'recovery_discord_down_title',
        'recovery_discord_down',
        'widget_title',
        'widget_note',
        'expect_heading',
        'expect.0',
        'expect.1',
        'expect.2',
        'result.added',
        'result.already_member',
        'result.unavailable',
        'result.expired',
    ];
}

/** Flatten a lang group array to dotted leaf paths. */
function joinLangLeafPaths(array $group, string $prefix = ''): array
{
    $paths = [];

    foreach ($group as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            array_push($paths, ...joinLangLeafPaths($value, $path));
        } else {
            $paths[] = $path;
        }
    }

    return $paths;
}

/**
 * Dev-facing literals allowed to stay hardcoded in JoinController: log lines
 * and exception messages. Both are never rendered — logs go to the trail the
 * funnel tests pin, exceptions go to the 500 page — so translating them would
 * only fork the log-grep contract for no member benefit.
 */
function joinLangDevLiterals(): array
{
    return [
        'Discord token exchange failed on the join journey.',
        'One-click join could not reach the bot contract.',
        'One-click join fell back to the invite.',
        'One-click join succeeded.',
        'The Discord driver returned an unexpected user object.',
        'The Discord Socialite driver is not registered.',
    ];
}

/**
 * Single-quoted literals in executable code: comments and docblocks stripped
 * first, so prose explaining the code cannot trip the scan. The controller
 * carries no `://` URL literal, so `//` always starts a comment here.
 */
function joinLangCodeLiterals(): array
{
    $source = (string) file_get_contents(app_path('Http/Controllers/JoinController.php'));

    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
    $source = (string) preg_replace('#^[ \t]*//.*$#m', '', $source);
    $source = (string) preg_replace('#(?<!:)//.*$#m', '', $source);

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);

    return $matches[1];
}

it('holds exactly the known join key set with no missing or stray key', function () {
    $group = trans('join');

    expect($group)->toBeArray('lang/en/join.php did not load as a group — check the file parses.');

    $actual = joinLangLeafPaths($group);
    sort($actual);

    $expected = joinLangExpectedKeys();
    sort($expected);

    expect($actual)->toBe($expected,
        'join lang drift. Missing: '.implode(', ', array_diff($expected, $actual))
        .'. Stray: '.implode(', ', array_diff($actual, $expected)).'.');

    // A key that resolves to an empty string renders as a hole in the page —
    // same class of bug as a missing key, so pin non-empty too.
    foreach ($expected as $key) {
        $copy = __("join.{$key}");

        expect($copy)->toBeString("join.{$key} did not resolve to a string")
            ->not->toBe("join.{$key}", "join.{$key} is missing")
            ->not->toBe('', "join.{$key} is empty");
    }
});

it('resolves every join key the controller and blades reference', function () {
    $files = [
        app_path('Http/Controllers/JoinController.php'),
        resource_path('views/join.blade.php'),
        resource_path('views/oauth/recovery.blade.php'),
        resource_path('views/profiles/show.blade.php'),
    ];

    $referenced = [];

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file);

        if (preg_match_all('/__\(\s*[\'"]join\.([A-Za-z0-9_.]+)[\'"]/', $contents, $matches)) {
            // A trailing dot means the key is built dynamically at runtime
            // (`__('join.result.'.session('join_result'))`) — the scan sees
            // only the `join.result.` prefix, which is not a key. The flashed
            // codes are pinned explicitly below, so skip the fragment.
            foreach ($matches[1] as $key) {
                if (! str_ends_with($key, '.')) {
                    $referenced[] = $key;
                }
            }
        }
    }

    // The flash key is dynamic (`__('join.result.'.session('join_result'))`),
    // so the scan above cannot see it. The flashed codes are the controller's
    // `done('…')` args plus the bot outcomes it forwards. Each must resolve.
    foreach (['added', 'already_member', 'unavailable', 'expired'] as $code) {
        $referenced[] = 'result.'.$code;
    }

    $referenced = array_values(array_unique($referenced));

    expect($referenced)->not->toBe([],
        'No join.* reference was found — the scan pattern stopped matching, not the code.');

    foreach ($referenced as $key) {
        expect(__("join.{$key}"))->not->toBe("join.{$key}", "join.{$key} is referenced but missing");
    }
});

it('keeps no hardcoded English sentence in JoinController', function () {
    $allowed = joinLangDevLiterals();
    $offenders = [];

    foreach (joinLangCodeLiterals() as $literal) {
        // Identifier-shaped literals (keys, routes, session names, scopes,
        // outcome codes) carry no prose. Anything with a space is a sentence
        // a member could read — unless it is an explicitly allowed dev string.
        if (! str_contains($literal, ' ')) {
            continue;
        }

        if (! in_array($literal, $allowed, true)) {
            $offenders[] = $literal;
        }
    }

    expect($offenders)->toBe([],
        'Hardcoded English in JoinController. Move it to lang/en/join.php or justify it in joinLangDevLiterals(): '.implode(' | ', $offenders));
});

it('renders the page title from the lang file, not a hardcoded string', function () {
    // The last hardcoded copy was `title="Join Together We Own"` in
    // join.blade.php — which also feeds the OG/Twitter title through the
    // layout fallback. The rendered title must equal the lang key so the two
    // cannot drift apart.
    config()->set('services.discord.invite_url', 'https://discord.gg/testinvite');

    $html = (string) $this->get(route('join'))->assertOk()->getContent();

    expect($html)->toContain('<title>'.e(__('join.heading')).'</title>')
        ->toContain('<meta property="og:title" content="'.e(__('join.heading')).'">');
});
