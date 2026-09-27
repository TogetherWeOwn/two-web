<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

it('redacts the member token from the entire exception chain and written log', function () {
    $token = 'member-token-that-must-never-be-written';

    config([
        'services.bot.url' => 'http://127.0.0.1:3001',
        'services.bot.secret' => 'test-shared-secret-that-is-long-enough-32',
        'services.bot.key_id' => 'web-test',
        'services.bot.timeout' => 2,
    ]);

    $user = new SocialiteUser;
    $user->setRaw(['id' => '111'])->map(['id' => '111', 'nickname' => 'wren']);
    $user->token = $token;

    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    $handler = new TestHandler;
    Log::swap(new LaravelLogger(new Monolog('testing', [$handler])));
    Http::fake(fn () => throw new ConnectionException('failed sending '.$token));

    $this->get('/join/callback?code=good&state=x')->assertSessionHas('join_result', 'unavailable');

    $written = collect($handler->getRecords())
        ->map(fn ($record) => (string) $record['formatted'])
        ->implode("\n");

    expect($written)->not->toContain($token)
        ->and(json_encode(session()->all()))->not->toContain($token);
});
