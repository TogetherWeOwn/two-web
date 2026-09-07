<?php

use App\Http\Controllers\Auth\DiscordLoginController;
use App\Support\Testing\DiscordProvider as TestingDiscordProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

function discordProviderFromController(): AbstractProvider
{
    $method = new ReflectionMethod(DiscordLoginController::class, 'discord');

    return $method->invoke(app(DiscordLoginController::class));
}

afterEach(function () {
    config(['services.dusk_test_seams' => false]);
    app()->detectEnvironment(fn () => 'testing');
});

it('uses the deterministic provider only for an explicitly enabled local Dusk run', function () {
    app()->detectEnvironment(fn () => 'local');
    config([
        'services.dusk_test_seams' => true,
        'services.discord.test_provider_url' => 'http://127.0.0.1:8765',
    ]);

    expect(discordProviderFromController())->toBeInstanceOf(TestingDiscordProvider::class);
});

it('cannot activate the deterministic provider in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['services.dusk_test_seams' => true]);

    $production = Mockery::mock(AbstractProvider::class);
    Socialite::shouldReceive('driver')->with('discord')->once()->andReturn($production);

    expect(discordProviderFromController())->toBe($production)
        ->not->toBeInstanceOf(TestingDiscordProvider::class);
});
