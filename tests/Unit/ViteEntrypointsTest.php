<?php

// Tests\TestCase stubs Vite so the PHP suite does not need a build (read the
// comment there for why). That trade has exactly one cost: `@vite(['…'])` can name
// an entrypoint that does not exist and Pest will not notice, because the stub
// returns an empty string for anything.
//
// This buys the cost back without a build. It checks the two things a real
// manifest would have proved: every entrypoint a Blade template asks for is
// declared in vite.config.js, and the source file is actually on disk. A typo in
// either place fails here in milliseconds instead of in Dusk twelve minutes later,
// or — worse — as a 500 on the home page.

/** Every entrypoint named by an @vite directive, as file => list of entries. */
function viteDirectiveEntrypoints(): array
{
    $found = [];
    $views = base_path('resources/views');

    if (! is_dir($views)) {
        return $found;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        // Both spellings the directive accepts: a bare string or an array.
        if (! preg_match_all('/@vite\(\s*(\[[^\]]*\]|[\'"][^\'"]*[\'"])/', $contents, $matches)) {
            continue;
        }

        foreach ($matches[1] as $argument) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $argument, $entries);

            foreach ($entries[1] as $entry) {
                $found[$relative][] = $entry;
            }
        }
    }

    return $found;
}

/** The `input:` list from vite.config.js — what a build will actually produce. */
function viteConfiguredInputs(): array
{
    $config = (string) file_get_contents(base_path('vite.config.js'));

    if (! preg_match('/input:\s*\[(.*?)\]/s', $config, $match)) {
        return [];
    }

    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $match[1], $entries);

    return $entries[1];
}

it('declares an input in vite.config.js for every entrypoint a view asks for', function () {
    $inputs = viteConfiguredInputs();

    expect($inputs)->not->toBe([], 'vite.config.js has no `input:` list — nothing would be built at all.');

    $undeclared = [];

    foreach (viteDirectiveEntrypoints() as $view => $entries) {
        foreach ($entries as $entry) {
            if (! in_array($entry, $inputs, true)) {
                $undeclared[] = $view.' asks for '.$entry;
            }
        }
    }

    expect($undeclared)->toBe([], "@vite entrypoint missing from vite.config.js `input:`. The build will not emit it and the page will 500:\n".implode("\n", $undeclared));
});

it('has the source file on disk for every input vite is told to build', function () {
    $missing = [];

    foreach (viteConfiguredInputs() as $input) {
        if (! is_file(base_path($input))) {
            $missing[] = $input;
        }
    }

    expect($missing)->toBe([], "vite.config.js `input:` names a file that does not exist:\n".implode("\n", $missing));
});
