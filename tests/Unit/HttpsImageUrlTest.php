<?php

use App\Rules\HttpsImageUrl;
use Illuminate\Support\Facades\Validator;

// TOG-7473: FeaturedContent.image_url is a moderator-typed string rendered
// into `<img src="...">` for every landing-page visitor. Filament's `url()`
// rule (Laravel `Str::isUrl`) accepts ftp:// and any https:// host, including
// a hostile .svg or a tracking pixel. HttpsImageUrl narrows it to https
// still-photo URLs; referrerpolicy="no-referrer" on the render side stops the
// host learning which page a visitor is on.
//
// DB-free by design (see phpunit.xml: the suite needs Postgres, which this
// box cannot reach), so these run anywhere the Unit suite runs.

function imageUrlPasses(?string $value): bool
{
    return Validator::make(
        ['image_url' => $value],
        ['image_url' => ['nullable', new HttpsImageUrl]]
    )->passes();
}

it('accepts https still-photo URLs, including query strings and folded case', function () {
    foreach ([
        'https://example.org/photo.jpg',
        'https://example.org/photo.jpeg?v=2',
        'https://cdn.example.org/a/b.png?x=1&y=2',
        'https://example.org/photo.webp',
        'https://example.org/anim.gif',
        'https://example.org/photo.avif',
        'https://example.org/PHOTO.JPG',
        'HTTPS://example.org/photo.jpg',
    ] as $url) {
        expect(imageUrlPasses($url))->toBeTrue("{$url} should pass");
    }
});

it('lets empty values through: the field is nullable, required stays the form decision', function () {
    expect(imageUrlPasses(null))->toBeTrue();
    expect(imageUrlPasses(''))->toBeTrue();
});

it('rejects non-https schemes', function () {
    foreach ([
        'ftp://example.org/photo.jpg',
        'http://example.org/photo.jpg',
        'javascript:alert(1)',
        'data:image/svg+xml,<svg></svg>',
    ] as $url) {
        expect(imageUrlPasses($url))->toBeFalse("{$url} should fail");
    }
});

it('rejects svg, extensionless, and non-image URLs', function () {
    foreach ([
        'https://evil.example/x.svg',
        'https://evil.example/pixel',
        'https://evil.example/photo.bmp',
        'https://evil.example/photo.jpg.exe',
        'https://evil.example/track/photo.jpg/',
    ] as $url) {
        expect(imageUrlPasses($url))->toBeFalse("{$url} should fail");
    }
});

it('rejects credentials embedded in the URL', function () {
    expect(imageUrlPasses('https://user:pass@example.org/photo.jpg'))->toBeFalse();
});

it('wires the rule into the moderator form, not just the rule class', function () {
    // The rule only protects visitors if the form actually applies it. Pin the
    // wiring at the source so a form refactor cannot silently drop it.
    $source = file_get_contents(base_path('app/Filament/Resources/FeaturedContents/Schemas/FeaturedContentForm.php'));

    expect($source)->toContain('HttpsImageUrl');
    expect($source)->toContain('->rule(new HttpsImageUrl)');
});

it('sends no referrer when visitors fetch a featured image', function () {
    // Third-party image hosts must not learn which page a visitor is on.
    foreach ([
        'resources/views/home.blade.php',
        'resources/views/design-lab/taste.blade.php',
        'resources/views/design-lab/hallmark.blade.php',
    ] as $view) {
        // NB: toContain() takes only needles — a message arg would be treated
        // as a second needle. Assert on str_contains so the view is named.
        expect(str_contains(file_get_contents(base_path($view)), 'referrerpolicy="no-referrer"'))
            ->toBeTrue("{$view} must not leak the referrer");
    }
});
