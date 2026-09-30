<?php

use App\Console\Commands\PoisonProbeJob;
use App\Console\Commands\QueuePoisonProbe;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    Bus::fake();
    DB::shouldReceive('connection')->never();
    config()->set('queue.default', 'probe_fixture');
    config()->set('queue.connections.probe_fixture', ['driver' => 'database', 'queue' => 'ordinary-work']);
    config()->set('queue.failed.table', 'probe_failures');
});

it('routes dispatch, the one-shot worker and the failed-row lookup to the same isolated queue', function (bool $recorded) {
    // Older ordinary work and another drill must not be selected by this worker.
    PoisonProbeJob::dispatch('ordinary-marker')->onConnection('probe_fixture')->onQueue('ordinary-work');
    PoisonProbeJob::dispatch('other-marker')->onConnection('probe_fixture')->onQueue('poison-probe-other');

    $command = Mockery::mock(QueuePoisonProbe::class.'[callSilent]', []);
    $command->setLaravel(app());
    $queue = null;
    $command->shouldReceive('callSilent')->once()->with('queue:work', Mockery::on(function ($arguments) use (&$queue) {
        $job = Bus::dispatched(PoisonProbeJob::class)->last();
        $queue = $job->queue;

        expect($queue)->toBe($job->marker)->toStartWith('poison-probe-')
            ->not->toBe('ordinary-work')->not->toBe('poison-probe-other')
            ->and($job->connection)->toBe('probe_fixture')
            ->and($arguments)->toBe([
                'connection' => 'probe_fixture',
                '--queue' => $queue,
                '--once' => true,
                '--tries' => 1,
                '--sleep' => 0,
                '--timeout' => 30,
            ]);

        return true;
    }))->andReturn(0);

    $count = Mockery::mock(Builder::class);
    $count->shouldReceive('count')->once()->andReturn(2);
    $lookup = Mockery::mock(Builder::class);
    $lookup->shouldReceive('where')->once()->with('connection', 'probe_fixture')->andReturnSelf();
    $lookup->shouldReceive('where')->once()->with('queue', Mockery::on(function ($value) use (&$queue) {
        return $value === $queue;
    }))->andReturnSelf();
    $lookup->shouldReceive('where')->once()->with('payload', 'like', Mockery::on(function ($value) use (&$queue) {
        return $value === '%'.$queue.'%';
    }))->andReturnSelf();
    $lookup->shouldReceive('orderByDesc')->once()->with('id')->andReturnSelf();
    $lookup->shouldReceive('first')->once()->andReturnUsing(function () use ($recorded, &$queue) {
        return $recorded ? (object) [
            'uuid' => 'synthetic-failed-uuid',
            'connection' => 'probe_fixture',
            'queue' => $queue,
            'failed_at' => '2026-09-30 12:00:00',
        ] : null;
    });
    DB::shouldReceive('table')->twice()->with('probe_failures')->andReturn($count, $lookup);

    $output = new BufferedOutput;
    $code = $command->run(new ArrayInput(['--json' => true]), $output);
    $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    Bus::assertDispatchedTimes(PoisonProbeJob::class, 3);
    expect($code)->toBe($recorded ? 0 : 1)
        ->and($payload['status'])->toBe($recorded ? 'ok' : 'error')
        ->and($payload['marker'])->toBe($queue)
        ->and($payload['worker_exit'])->toBe(0)
        ->and($payload['failed_jobs_before'])->toBe(2);

    if ($recorded) {
        expect($payload['failed_uuid'])->toBe('synthetic-failed-uuid')
            ->and($payload['failed_connection'])->toBe('probe_fixture')
            ->and($payload['failed_queue'])->toBe($queue);
    } else {
        expect($payload)->not->toHaveKey('failed_uuid');
    }
})->with([true, false]);

it('refuses unsupported drivers before dispatch, consumption or failed-row queries', function (string $driver) {
    config()->set('queue.connections.probe_fixture.driver', $driver);
    DB::shouldReceive('table')->never();
    $command = Mockery::mock(QueuePoisonProbe::class.'[callSilent]', []);
    $command->setLaravel(app());
    $command->shouldReceive('callSilent')->never();
    $output = new BufferedOutput;

    $code = $command->run(new ArrayInput(['--json' => true]), $output);
    $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(1)
        ->and($payload['status'])->toBe('error')
        ->and($payload['driver'])->toBe($driver);
    Bus::assertNothingDispatched();
})->with(['sync', 'redis']);
