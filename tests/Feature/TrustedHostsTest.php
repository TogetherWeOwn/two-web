<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // TrustHosts deliberately skips local/testing. Exercise the deployed stack,
    // with a synthetic APP_URL rather than any deployment-specific hostname.
    $this->app['env'] = 'production';
    config()->set('app.url', 'https://community.example.test');
    config()->set('app.debug', false);
});

it('rejects forged hosts before rendering canonical URLs or issuing redirects', function (string $path, array $headers) {
    // Symfony derives Host from an absolute test URL, overriding a supplied
    // Host header. Put the forged host in the URL so this is a real attack.
    $host = $headers['Host'] ?? 'community.example.test';
    $response = $this->get('https://'.$host.$path, $headers);

    $response->assertStatus(400)->assertHeaderMissing('Location');
    expect((string) $response->getContent())->not->toContain('https://attacker.example');
})->with([
    'canonical' => ['/'],
    'join canonical' => ['/join'],
    'login callback URL' => ['/auth/discord/redirect'],
    'break-glass redirect' => ['/discord'],
    'password reset probe' => ['/forgot-password'],
])->with([
    'Host' => [['Host' => 'attacker.example']],
    'forwarded host' => [['X-Forwarded-Host' => 'attacker.example']],
    'unapproved subdomain' => [['Host' => 'attacker.community.example.test']],
    'suffix lookalike' => [['Host' => 'community.example.test.attacker.example']],
    'regex lookalike' => [['Host' => 'communityXexampleXtest']],
]);

it('keeps legitimate canonical URLs on the configured host', function (string $environment) {
    $this->app['env'] = $environment;

    $this->get('https://community.example.test/')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://community.example.test">', false);
})->with(['production', 'staging']);

it('keeps proxy HTTPS and the approved OAuth callback working', function () {
    $response = $this->get('http://community.example.test/auth/discord/redirect', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'community.example.test',
    ])->assertRedirect();

    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['redirect_uri'])->toBe('https://community.example.test/auth/discord/callback');
});

it('keeps the legitimate break-glass redirect temporary and uncacheable', function () {
    config()->set('services.discord.invite_url', 'https://discord.gg/host-proof');

    $response = $this->get('https://community.example.test/discord')
        ->assertStatus(302)
        ->assertRedirect('https://discord.gg/host-proof');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('does not expose a password-reset flow in this Discord-only application', function () {
    expect(Route::has('password.request'))->toBeFalse()
        ->and(Route::has('password.email'))->toBeFalse()
        ->and(Route::has('password.reset'))->toBeFalse()
        ->and(Route::has('password.update'))->toBeFalse();

    $this->get('https://community.example.test/forgot-password')->assertNotFound();
    $this->get('https://community.example.test/reset-password/proof-token')->assertNotFound();
});
