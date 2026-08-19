<?php

// This app launches on a subdomain and moves to togetherweown.com later (TWO-38,
// TWO-41). That move is only cheap if the hostname lives in exactly one place:
// APP_URL. One hardcoded "togetherweown.com" in a Blade template or a redirect is
// enough to turn a DNS change into a bug hunt, and it will be found by a member,
// not by us. So: fail the build instead.
//
// Use url(), route(), config('app.url') or an env-driven config value.

$domains = ['togetherweown.com', 'togetherweown.net', 'two.gg'];

// Deliberate exceptions. Add a file here only with a reason a reviewer can argue
// with — not to make a red build go green.
$allowed = [];

$scan = ['app', 'config', 'routes', 'resources/views'];

it('keeps every hostname out of the code and in the config', function () use ($domains, $allowed, $scan) {
    $offenders = [];

    foreach ($scan as $dir) {
        $path = base_path($dir);

        if (! is_dir($path)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file->getPathname());

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            foreach (file($file->getPathname()) as $number => $line) {
                foreach ($domains as $domain) {
                    if (str_contains(strtolower($line), $domain)) {
                        $offenders[] = $relative.':'.($number + 1).' — '.trim($line);
                    }
                }
            }
        }
    }

    expect($offenders)->toBe([], "Hardcoded hostname. Use APP_URL or a config value:\n".implode("\n", $offenders));
});
