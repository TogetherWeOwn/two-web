<?php

namespace App\Http\Controllers;

use App\Support\QueueHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `GET /up` — the deploy and uptime signal, with the queue depth folded in
 * ([TOG-8414](/TOG/issues/TOG-8414)).
 *
 * Laravel's built-in `/up` (bootstrap/app.php `health: '/up'`) answers the
 * wrong question here: it only knows healthy (200) versus booting an exception
 * (500). A silent queue backlog is silent RSVP delay — no 500, no log line,
 * the Discord mirror just stops catching up — so `/up` said "up" through the
 * one outage shape members actually feel. This controller keeps the framework
 * route for the two shapes it owns (boot failure, maintenance) and answers
 * the third one the box probe (`queue:check-depth`) already knew how to see.
 *
 * The contract the deploy poll and the monitors rely on:
 *
 *   - healthy or degraded both answer 200. A backlog is not an outage, and
 *     the deploy poll (`curl -f` in `.github/workflows/deploy.yml`) must keep
 *     passing through one — `/up` distinguishes shapes in the body, not the
 *     status code.
 *   - `status` is one of `healthy`, `degraded`. `degraded` at or above the
 *     warn threshold (QueueHealth::WARN_AT pending jobs), still `degraded` —
 *     never down — past critical. The queue numbers always ride along so a
 *     dashboard reads the lag without a second probe.
 *   - the queue read can never sink the endpoint. A driver with no countable
 *     depth, or a queue table that will not answer, is reported in the body
 *     (`queue.status: unknown`) and the endpoint stays on the last known
 *     application health. An `/up` that 500s because the queue is slow
 *     mistakes the smoke detector for the fire.
 *
 * Placement: `routes/funnel.php`, with the deliberately empty middleware
 * stack — not `routes/web.php`. Two reasons. First, `SESSION_DRIVER=database`
 * everywhere we ship, so a `web` route opens Postgres in StartSession before
 * the controller runs, and today's `/up` answers 200 through an app-DB outage
 * (the framework route never touches the database); moving the probe into
 * `web` would turn a DB blip into a failed deploy poll. Here the queue read
 * fails into `unknown` and the endpoint still answers. Second, monitors poll
 * this with no session, no CSRF state and nothing to abuse, so there is
 * nothing for the `web` stack to add and no throttle to put in front of it.
 * The route URI is pinned in `tests/Unit/ProductionRouteAllowlistTest.php`
 * (`GET|HEAD up`); moving it elsewhere renames the only health check the
 * deploy knows.
 */
class HealthCheckController
{
    public function __invoke(): JsonResponse
    {
        $queue = $this->queueHealth();

        $status = $queue['status'] === 'degraded' ? 'degraded' : 'healthy';

        return response()->json([
            'status' => $status,
            'queue' => $queue,
        ], 200);
    }

    /**
     * The queue slice of the payload. Never throws: every probe failure is a
     * reported `unknown`, never a 500.
     *
     * @return array{status: string, pending: ?int, delayed: ?int, reserved: ?int, total: ?int, failed: ?int, oldest_pending_age_seconds: ?int, warn_at: int, critical_at: int, detail: ?string}
     */
    private function queueHealth(): array
    {
        $driver = QueueHealth::driver();

        if (! QueueHealth::isCountable()) {
            return $this->unknownQueue("queue driver '".($driver ?? 'unconfigured')."' has no countable depth.");
        }

        try {
            // Single shared counting with the box probe (CheckQueueDepth via
            // QueueHealth::measure): the two readers must never disagree
            // about what "deep" means.
            $depth = QueueHealth::measure();
        } catch (Throwable $e) {
            // Class only, never the message: the queue tables carry job
            // payloads, and the endpoint must not leak one into a log.
            Log::warning('Health check could not read the queue depth; reporting unknown.', [
                'exception' => $e::class,
            ]);

            return $this->unknownQueue(null);
        }

        // Degraded, not down, when deep: a backlog is RSVP lag, not an
        // outage, and the deploy poll must keep passing through one.
        return [
            'status' => $depth['pending'] >= QueueHealth::WARN_AT ? 'degraded' : 'healthy',
            'pending' => $depth['pending'],
            'delayed' => $depth['delayed'],
            'reserved' => $depth['reserved'],
            'total' => $depth['total'],
            'failed' => $depth['failed'],
            'oldest_pending_age_seconds' => $depth['oldest_pending_age_seconds'],
            'warn_at' => QueueHealth::WARN_AT,
            'critical_at' => QueueHealth::CRITICAL_AT,
            'detail' => null,
        ];
    }

    /**
     * @return array{status: string, pending: ?int, delayed: ?int, reserved: ?int, total: ?int, failed: ?int, oldest_pending_age_seconds: ?int, warn_at: int, critical_at: int, detail: ?string}
     */
    private function unknownQueue(?string $detail): array
    {
        return [
            'status' => 'unknown',
            'pending' => null,
            'delayed' => null,
            'reserved' => null,
            'total' => null,
            'failed' => null,
            'oldest_pending_age_seconds' => null,
            'warn_at' => QueueHealth::WARN_AT,
            'critical_at' => QueueHealth::CRITICAL_AT,
            'detail' => $detail,
        ];
    }
}
