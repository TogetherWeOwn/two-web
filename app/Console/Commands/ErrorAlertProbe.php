<?php

namespace App\Console\Commands;

use App\Support\ErrorAlertRateLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\PsrHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/**
 * A drill for the log-based error alert (TOG-8730).
 *
 * The acceptance on that card is that a 500 in staging produces an
 * operator-visible alert within the documented path. On a box the drill is:
 * run this, tail the log, see the alert line. The command throws and reports
 * a marker exception through the same `report` listener in bootstrap/app.php
 * that a real 500 travels — it does not write the log line itself, so a
 * passing probe proves the wiring, not just the printer.
 *
 *   php artisan error-alert:probe --json
 *
 * The marker carries a unique stamp so the alert line can be found in a busy
 * log, and the noise guard is shown rather than asserted-in-passing: after
 * the first alert the command fires the same fingerprint again and reports
 * whether the second was muted, which is the rate limit doing its job.
 */
class ErrorAlertProbe extends Command
{
    protected $signature = 'error-alert:probe
                            {--json : Emit the findings as JSON instead of a table.}';

    protected $description = 'Prove the log-based error alert fires once, then mutes repeats (TOG-8730)';

    public function handle(): int
    {
        $marker = 'error-alert-probe-'.now()->format('YmdHis').'-'.substr((string) str()->uuid(), 0, 8);

        // Capture the listener's records and forward them to the configured
        // logger, so the marker also reaches the log the operator will tail.
        // Restore the original logger even if reporting or delivery throws.
        $handler = new TestHandler;
        $original = Log::getFacadeRoot();
        Log::swap(new \Illuminate\Log\Logger(new Logger('error-alert-probe', [
            $handler,
            new PsrHandler($original),
        ])));

        try {
            $exception = new \RuntimeException('error-alert:probe self-failure for marker '.$marker);
            report($exception);
            $first = $this->alertCount($handler, $marker);

            report($exception);
            // Count, not presence: the first alert's record is still in the
            // handler, so "did the repeat fire" is whether the count grew.
            $repeatMuted = $this->alertCount($handler, $marker) === $first;
            $first = $first > 0;
        } finally {
            Log::swap($original);
        }

        if (! $first) {
            return $this->report([
                'status' => 'error',
                'marker' => $marker,
                'detail' => "the marker exception produced no 'Unhandled exception.' alert; the report listener in bootstrap/app.php did not fire.",
            ], self::FAILURE);
        }

        if (! $repeatMuted) {
            return $this->report([
                'status' => 'error',
                'marker' => $marker,
                'detail' => 'the first alert fired but the repeat was not muted; the ErrorAlertRateLimit noise guard is not holding.',
            ], self::FAILURE);
        }

        return $this->report([
            'status' => 'ok',
            'marker' => $marker,
            'fingerprint' => ErrorAlertRateLimit::fingerprint($exception, request()->path() ?: 'console'),
            'detail' => "alert fired once for marker {$marker} and the repeat was muted — tail the log for 'Unhandled exception.' to complete the drill on a box.",
        ], self::SUCCESS);
    }

    private function alertCount(TestHandler $handler, string $marker): int
    {
        $count = 0;

        foreach ($handler->getRecords() as $record) {
            if ($record->message === 'Unhandled exception.'
                && str_contains((string) json_encode($record->context), $marker)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param  array<string, mixed>  $payload */
    private function report(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        foreach ($payload as $key => $value) {
            $shown = $value === null ? '<none>' : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
            $this->line(sprintf('  %-20s %s', $key, $shown));
        }

        return $exit;
    }
}
