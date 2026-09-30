<?php

use App\Console\Commands\PoisonProbeJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;

/**
 * A job that fails itself the way the real jobs do: SyncEventToDiscord's
 * failWith and CallInternalAction's failWith call fail() from inside
 * handle() on a terminal refusal, rather than throwing and letting the
 * worker give up. The alert must fire for that path too.
 */
class SelfFailingProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        $this->fail(new RuntimeException('self-fail for the listener test'));
    }
}

/*
 * The failed-job half of the queue health signal (TOG-6948).
 *
 * `queue:check-depth` (TOG-6773) proves a stuck queue surfaces as a number;
 * this proves a dead job surfaces as an alert. The chain under test:
 *
 *   1. a poison job dispatched on the database driver fails and lands in
 *      `failed_jobs` — the dead letter exists, it is not swallowed;
 *   2. the `Queue::failing` listener in AppServiceProvider logs one critical
 *      line with the class, queue and exception message — the alert fires;
 *   3. `queue:poison-probe` reports the failed row, so the drill on a box is
 *      one command plus a log tail rather than three queries from memory.
 *
 * What is deliberately not asserted: the exact log transport. The suite runs
 * on LOG_CHANNEL=stack/single and a box runs whatever its `.env` says; the
 * contract is the critical record reaching the log, not which file it lands
 * in. The staging half of the acceptance — run the probe on the box, tail the
 * log, see the line — is a runbook step in docs/ci.md, because no test can
 * see a box's log file.
 */

beforeEach(function () {
    // phpunit.xml runs the suite on QUEUE_CONNECTION=sync, where a failing job
    // throws straight back at the dispatcher and never touches failed_jobs.
    // The dead-letter table only means something against the database driver,
    // the same reason the depth tests point the default connection at it.
    config()->set('queue.default', 'database');
});

it('lands a thrown job in failed_jobs with its payload intact', function () {
    $marker = 'test-poison-'.substr((string) str()->uuid(), 0, 8);

    PoisonProbeJob::dispatch($marker);

    Artisan::call('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 30]);

    $row = DB::table('failed_jobs')->where('payload', 'like', '%'.$marker.'%')->first();

    expect($row)->not->toBeNull()
        ->and($row->queue)->toBe('default')
        ->and($row->payload)->toContain('PoisonProbeJob')
        ->and($row->exception)->toContain($marker);
});

it('logs one critical alert per failed job, naming the class and the cause', function () {
    $handler = new TestHandler;
    Log::swap(new Illuminate\Log\Logger(new Logger('test', [$handler])));

    $marker = 'test-alert-'.substr((string) str()->uuid(), 0, 8);

    PoisonProbeJob::dispatch($marker);

    Artisan::call('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 30]);

    $alerts = collect($handler->getRecords())
        ->filter(fn ($record) => $record->message === 'Queue job failed.'
            && $record->level === Level::Critical);

    expect($alerts)->toHaveCount(1);

    $context = $alerts->first()->context;

    expect($context['job'])->toContain('PoisonProbeJob')
        ->and($context['message'])->toContain($marker);
});

it('reports the dead letter through the probe command', function () {
    $code = Artisan::call('queue:poison-probe', ['--json' => true]);

    expect($code)->toBe(0);

    $payload = json_decode(Artisan::output(), true);

    expect($payload['status'])->toBe('ok')
        ->and($payload['failed_uuid'])->not->toBeEmpty()
        ->and(DB::table('failed_jobs')->where('uuid', $payload['failed_uuid'])->exists())->toBeTrue();
});

it('isolates the drill from older ordinary work and another probe queue', function () {
    config()->set('queue.connections.probe_fixture', config('queue.connections.database'));
    config()->set('queue.connections.probe_fixture.queue', 'ordinary-work');
    config()->set('queue.default', 'probe_fixture');

    SelfFailingProbeJob::dispatch();
    PoisonProbeJob::dispatch('other-probe')->onQueue('poison-probe-other');
    $pending = DB::table('jobs')->orderBy('id')->get();

    expect($pending)->toHaveCount(2);

    $code = Artisan::call('queue:poison-probe', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $failed = DB::table('failed_jobs')->where('uuid', $payload['failed_uuid'])->first();

    expect($code)->toBe(0)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['failed_connection'])->toBe('probe_fixture')
        ->and($payload['failed_queue'])->toBe($payload['marker'])
        ->and($failed->payload)->toContain($payload['marker'])
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->orderBy('id')->get()->all())->toEqual($pending->all());
});

it('refuses to dispatch while the app is down for maintenance', function () {
    // Laravel's --once worker returns without consuming while the app is
    // down, so a dispatched probe would strand on its disposable queue —
    // the refusal must happen before dispatch, with zero side effects.
    $this->artisan('down')->assertSuccessful();

    try {
        $code = Artisan::call('queue:poison-probe', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($code)->toBe(1)
            ->and($payload['status'])->toBe('error')
            ->and($payload)->not->toHaveKey('marker')
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    } finally {
        $this->artisan('up')->assertSuccessful();
    }
});

it('refuses a non-database driver instead of reporting a healthy zero', function () {
    config()->set('queue.default', 'sync');

    $code = Artisan::call('queue:poison-probe', ['--json' => true]);

    expect($code)->toBe(1)
        ->and(json_decode(Artisan::output(), true)['status'])->toBe('error');
});

it('alerts on a self-failed job through the worker too', function () {
    // SyncEventToDiscord::failWith and CallInternalAction::failWith call
    // fail() themselves rather than letting the worker give up — and
    // Job::fail() dispatches JobFailed in a finally block either way, so the
    // alert must fire for that path as well. A job that calls fail() inside
    // handle() exercises exactly that path through a real worker run.
    $handler = new TestHandler;
    Log::swap(new Illuminate\Log\Logger(new Logger('test', [$handler])));

    SelfFailingProbeJob::dispatch();

    Artisan::call('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 30]);

    $alerts = collect($handler->getRecords())
        ->filter(fn ($record) => $record->message === 'Queue job failed.'
            && str_contains((string) json_encode($record->context), 'SelfFailingProbeJob'));

    expect($alerts)->toHaveCount(1);
});
