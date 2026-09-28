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

// `public` is scanned too: a static public/robots.txt with a hardcoded Sitemap
// host once shadowed the dynamic route on staging (TOG-7071), because nginx
// try_files serves a static file before Laravel ever sees the request.
$scan = ['app', 'config', 'routes', 'resources/views', 'public'];

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

            // Binary assets (fonts, images) carry no hostnames worth gating;
            // scanning them only risks garbage diffs on failure output.
            if (in_array(strtolower($file->getExtension()), ['ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'mp4', 'pdf', 'zip'], true)) {
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
