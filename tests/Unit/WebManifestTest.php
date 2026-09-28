<?php

it('ships a same-origin standalone manifest with stable identity and launch URL', function () {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toMatchArray([
        'id' => '/',
        'name' => 'Together We Own',
        'short_name' => 'TWO',
        'lang' => 'en',
        'start_url' => '/',
        'scope' => '/',
        'display' => 'standalone',
        'background_color' => '#f1eadb',
        'theme_color' => '#0b0714',
    ]);
});

it('ships real PNGs for both required icon sizes and a separate maskable icon', function () {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    $sizesByPurpose = [];

    foreach ($manifest['icons'] as $icon) {
        expect($icon['src'])->toMatch('#^/icons/[a-z0-9-]+\.png$#');
        expect($icon['type'])->toBe('image/png');
        $path = public_path($icon['src']);
        expect($path)->toBeFile();
        $image = getimagesize($path);
        expect($image)->not->toBeFalse();
        expect($image['mime'])->toBe($icon['type']);
        expect($image[0].'x'.$image[1])->toBe($icon['sizes']);
        $sizesByPurpose[$icon['purpose']][] = $icon['sizes'];
    }

    expect($sizesByPurpose['any'])->toContain('192x192', '512x512');
    expect($sizesByPurpose['maskable'])->toContain('512x512');
    $apple = getimagesize(public_path('icons/apple-touch-icon.png'));
    expect([$apple[0], $apple[1], $apple['mime']])->toBe([180, 180, 'image/png']);
});

it('renders the manifest and matching chrome color in each shared layout scheme', function (?string $scheme) {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
    $html = $this->blade('<x-layouts.app :scheme="$scheme">Test page</x-layouts.app>', ['scheme' => $scheme]);

    $html->assertSee('<link rel="manifest" href="/site.webmanifest">', false)
        ->assertSee('<meta name="theme-color" content="'.$manifest['theme_color'].'">', false)
        ->assertSee('<link rel="icon" href="/icons/icon-192.png" type="image/png" sizes="192x192">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png" sizes="180x180">', false);
})->with([null, 'ledger', 'taste', 'hallmark']);

it('pins the static manifest MIME without depending on the nginx MIME table', function () {
    $nginx = file_get_contents(base_path('nginx.template.conf'));

    expect($nginx)->toMatch('/location = \/site\.webmanifest\s*\{\s*types\s*\{\s*\}\s*default_type application\/manifest\+json;\s*try_files \$uri =404;\s*\}/');
});
