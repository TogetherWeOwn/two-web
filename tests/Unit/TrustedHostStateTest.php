<?php

use Illuminate\Http\Request;

it('populates Symfony trusted hosts through the production middleware', function () {
    $this->app['env'] = 'production';
    config()->set('app.url', 'https://community.example.test');
    config()->set('services.discord.invite_url', 'https://discord.gg/host-proof');

    // The funnel exercises the real global middleware without a database.
    $this->get('https://community.example.test/discord')
        ->assertRedirect('https://discord.gg/host-proof');

    expect(Request::getTrustedHosts())->toHaveCount(1);
});

it('starts the next test without a previous applications trusted hosts', function () {
    expect($this->app->environment())->toBe('testing');
    expect(Request::getTrustedHosts())->toBe([]);

    config()->set('services.discord.invite_url', 'https://discord.gg/host-proof');
    $this->get('http://staging.example.test/discord', ['X-Forwarded-Proto' => 'https'])
        ->assertRedirect('https://discord.gg/host-proof');
})->depends('it populates Symfony trusted hosts through the production middleware');
