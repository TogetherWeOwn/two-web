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

// The moderator panel's stylesheet is Filament's own chrome, not the design
// system, and it is exempt from the rule below. The exemption is narrow and it
// is conditional — see the test immediately after this one, which is what makes
// it safe.
$adminTheme = 'resources/css/filament/admin/theme.css';

it('declares the typeface in exactly one place', function () use ($tokens, $adminTheme) {
    // The Designer owns the font stack and it lives in the base layer of
    // two.css. A `font-family` anywhere else — or a stray Tailwind `@theme`
    // block, which is how Laravel's default Instrument Sans got here — means
    // two sources of truth and a component that quietly stops matching the
    // spec.
    //
    // /admin is the one exemption, and it is not a relaxation of the rule so
    // much as a statement of where the rule applies. The panel is Filament's
    // vendor chrome in Filament's own Inter Variable; it has never rendered
    // Archivo and TOG-1008 did not change that — measured, both before and
    // after, as `Inter Variable` computed on `.fi-body`. What TOG-1008 changed
    // is *where the vendor's font tokens sit*: tree-shaking the 615KB
    // stylesheet meant replacing Filament's `index.css` manifests with the
    // subset of imports this panel renders, and the manifests' `@theme` block
    // had to come across verbatim with them. So the same vendor bytes that used
    // to sit unscanned in `vendor/` now sit in `resources/` and this scan finds
    // them. Nothing about the public site's typeface moved.
    //
    // The exemption is only safe while that file's font tokens really are the
    // vendor's and not somebody's opinion, which is the next test's job.
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('resources'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        if (! $file->isFile() || $relative === $tokens || $relative === $adminTheme) {
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

it('exempts the admin panel theme only while its font tokens are still the vendor\'s', function () use ($adminTheme) {
    // The exemption above is worth exactly this test. Without it, that file is a
    // hole in the one-typeface rule that anyone could set a font in — and the
    // failure would be silent, because a panel in the wrong typeface still
    // renders, still passes axe, and still scores fine.
    //
    // So: every font token in the panel theme has to be a byte-for-byte copy of
    // the installed Filament manifest it came from. Change one and this fails,
    // whether the change is a typo, a well-meant `Archivo` (which belongs in
    // two.css, not here), or a Filament upgrade that moved a token underneath
    // us. The vendor file is the comparison, so there is nothing to keep in
    // sync by hand.
    $vendorManifest = base_path('vendor/filament/filament/resources/css/index.css');

    expect($vendorManifest)->toBeReadableFile();

    $fontLines = static function (string $path): array {
        $lines = [];

        foreach (file($path) as $line) {
            if (preg_match('/font-family|--font-/', $line)) {
                $lines[] = trim($line);
            }
        }

        return $lines;
    };

    $ours = $fontLines(base_path($adminTheme));
    $vendors = $fontLines($vendorManifest);

    expect($ours)->not->toBeEmpty(
        "{$adminTheme} declares no font tokens at all. Filament resolves every ".
        '`font-sans` utility in the panel against them, so without the copied '.
        '@theme block the panel falls back to the browser default — measured as '.
        'plain -apple-system/Segoe UI instead of Inter Variable. Restore the '.
        'block, or drop the exemption in the test above.'
    );

    expect($ours)->toBe(
        $vendors,
        "The font tokens in {$adminTheme} are no longer a verbatim copy of ".
        "{$vendorManifest}. That file is exempt from the one-typeface rule only ".
        'because its font tokens are the vendor\'s own, so this is either a '.
        'Filament upgrade to copy across, or a typeface decision in the wrong '.
        'file — the design system lives in resources/css/two.css.'
    );
});

it('preloads the headline font and chooses the page color scheme before paint', function () {
    // Archivo remains the headline face and still needs the preload. The two
    // homepage presentations are deliberate light schemes; the layout decides
    // that before CSS parses so neither scheme flashes dark native controls.
    $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

    expect($layout)->toContain('rel="preload"', 'href="/fonts/archivo-latin.woff2"');
    expect($layout)->toContain("['ledger', 'hallmark', 'taste']");
    expect($layout)->toContain('name="color-scheme"');
    expect($layout)->toContain("\$lightScheme ? 'light' : 'dark'");
    expect($layout)->toContain("\$lightScheme ? '' : 'dark '");
    expect($layout)->toContain("'ledger' => 'bg-ledger-paper text-ledger-ink '");
    expect($layout)->toContain("'taste' => 'bg-taste-paper text-taste-ink '");
});

it('keeps the homepage inside the layout main landmark', function () {
    $home = file_get_contents(resource_path('views/home.blade.php'));

    expect($home)->toContain('<div id="lobby"');
    expect($home)->not->toContain('<main');
});
