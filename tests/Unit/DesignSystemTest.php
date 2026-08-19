<?php

// The design system (TWO-19) is vendored into this repo from the two-design
// repo: `resources/css/two.css` plus the self-hosted Archivo files in
// `public/fonts/`. Vendoring means it can drift, and every way it drifts is
// silent — no error, just a page that renders in Arial or a hero that jumps on
// first paint. These are the three ways it has a real chance of breaking.
//
// If one of these fails, do not edit the assertion. Either restore the file or
// talk to the Designer.

$tokens = 'resources/css/two.css';

it('ships every font file the tokens ask the browser for', function () use ($tokens) {
    // two.css @font-face rules point at /fonts/*.woff2. Those are served
    // straight off the filesystem, not through Vite, so nothing in the build
    // notices when one is missing — the browser silently falls back to Arial.
    $css = file_get_contents(base_path($tokens));

    preg_match_all("#url\('(/fonts/[^']+)'\)#", $css, $matches);

    expect($matches[1])->not->toBeEmpty("No @font-face url() found in {$tokens} — has the vendored copy gone stale?");

    foreach ($matches[1] as $url) {
        expect(public_path($url))->toBeFile("{$tokens} asks for {$url} but public{$url} is not in the repo.");
    }
});

it('declares the typeface in exactly one place', function () use ($tokens) {
    // The Designer owns the font stack and it lives in the base layer of
    // two.css. A `font-family` anywhere else — or a stray Tailwind `@theme`
    // block, which is how Laravel's default Instrument Sans got here — means
    // two sources of truth and a component that quietly stops matching the
    // spec.
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('resources'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        if (! $file->isFile() || $relative === $tokens) {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match('/font-family|--font-/', $line)) {
                $offenders[] = $relative.':'.($number + 1).' — '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([], "The typeface is set in {$tokens} and nowhere else:\n".implode("\n", $offenders));
});

it('preloads the headline font and tells the browser the page is dark', function () {
    // Archivo carries the width axis the headline uses. Without the preload the
    // hero reflows on first paint and the join button moves under the reader's
    // thumb. Without color-scheme the browser paints scrollbars and form
    // controls light before our stylesheet has parsed.
    $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

    expect($layout)->toContain('rel="preload"', 'href="/fonts/archivo-latin.woff2"');
    expect($layout)->toContain('name="color-scheme"', 'content="dark"');
    expect($layout)->toContain('<html lang="en" class="dark');
});
