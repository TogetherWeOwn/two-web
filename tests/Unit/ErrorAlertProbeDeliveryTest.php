<?php

use App\Support\ErrorAlertRateLimit;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

beforeEach(function () {
    // No database, disk log or external alert: both the configured logger and
    // the limiter store are in-memory fixtures before the command starts.
    DB::shouldReceive('connection')->never();
    $this->alertCache = new Repository(new ArrayStore);
    RateLimiter::swap(new CacheRateLimiter($this->alertCache));
    $this->logHandler = new TestHandler;
    $this->originalLogger = new Illuminate\Log\Logger(new Logger('configured', [$this->logHandler]));
    Log::swap($this->originalLogger);
});

it('delivers exactly one matching critical marker to the configured logger and restores it', function () {
    $code = Artisan::call('error-alert:probe', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['marker'])->toStartWith('error-alert-probe-')
        ->and(Log::getFacadeRoot())->toBe($this->originalLogger);

    $alerts = array_values(array_filter($this->logHandler->getRecords(),
        fn ($record) => $record->message === 'Unhandled exception.' && $record->level === Level::Critical));

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->context['message'])->toBe('error-alert:probe self-failure for marker '.$payload['marker'])
        ->and($alerts[0]->context['exception'])->toBe(RuntimeException::class)
        ->and($payload['fingerprint'])->toBe(ErrorAlertRateLimit::fingerprint(
            new RuntimeException, $alerts[0]->context['route']))
        ->and($this->alertCache->get('error-alert:'.sha1($payload['fingerprint'])))->toBe(1);

    Log::info('after-probe');
    expect($this->logHandler->hasInfo('after-probe'))->toBeTrue();
});

it('restores the configured logger when delivery throws', function () {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('log')->once()->with('critical', 'Unhandled exception.', Mockery::type('array'))
        ->andThrow(new RuntimeException('synthetic delivery failure'));
    Log::swap($logger);

    expect(fn () => Artisan::call('error-alert:probe', ['--json' => true]))
        ->toThrow(RuntimeException::class, 'synthetic delivery failure');
    expect(Log::getFacadeRoot())->toBe($logger);
});

it('reports unmuted repeats as clean error JSON while retaining both delivered alerts', function () {
    RateLimiter::shouldReceive('tooManyAttempts')->twice()->andReturn(false);
    RateLimiter::shouldReceive('hit')->twice();

    $code = Artisan::call('error-alert:probe', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $alerts = array_values(array_filter($this->logHandler->getRecords(),
        fn ($record) => $record->message === 'Unhandled exception.' && $record->level === Level::Critical));

    expect($code)->toBe(1)
        ->and($payload['status'])->toBe('error')
        ->and($payload['detail'])->toContain('repeat was not muted')
        ->and($alerts)->toHaveCount(2)
        ->and(Log::getFacadeRoot())->toBe($this->originalLogger);

    foreach ($alerts as $alert) {
        expect($alert->context['message'])->toContain($payload['marker']);
    }
});
