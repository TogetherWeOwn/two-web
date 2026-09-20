<?php

namespace App\Console\Commands;

use App\Jobs\CallInternalAction;
use App\Services\Bot\Announcement;
use App\Services\Bot\AnnouncementResult;
use App\Services\Bot\EventUpsert;
use App\Services\Bot\EventUpsertResult;
use App\Services\Bot\Exceptions\BotNotConfiguredException;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionFailure;
use App\Services\Bot\RoleAssignment;
use App\Services\Bot\RoleAssignResult;
use App\Services\Bot\SettingWriteResult;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives all three live actions against a *running* bot (TOG-470).
 *
 * TOG-463 asks QA to prove "the Lead can call all three actions from a Laravel
 * job against staging". This is that, turned into a command, because it is a
 * list with numbers in it and a model re-improvising it every time is how a
 * green tick stops meaning anything. Same input, same output, forever.
 *
 * It is the mirror of two-bot's `scripts/internal-actions-acceptance.ts`, and
 * deliberately covers less: that harness owns the adversarial half (tampered
 * bodies, verbatim replays, reading `internal_action_log`) and can reach the
 * bot's database to do it. This one answers the question that is ours — does
 * the *website's* job, signer and client work against a real endpoint.
 *
 *   php artisan bot:internal-action-smoke \
 *     --discord-id=900000000000009999 \
 *     --role-key=rocketleague \
 *     --channel-key=qa-throwaway
 *
 * Exit codes match the reference harness: 0 every check passed, 1 a check
 * failed, 2 misconfigured.
 *
 * **The channel key must name a throwaway channel.** This posts a real
 * announcement and has no way to delete it.
 */
class BotInternalActionSmoke extends Command
{
    protected $signature = 'bot:internal-action-smoke
        {--discord-id= : Snowflake to assign the role to}
        {--role-key= : A key from the bot role allowlist, not a role ID}
        {--channel-key= : A THROWAWAY channel key — a real announcement gets posted}
        {--event-key= : Defaults to a fresh random key, so a run creates rather than updates}';

    protected $description = 'Call role.assign, announcement.post and event.upsert on the bot against a running host.';

    /** @var list<string> */
    private array $failures = [];

    public function handle(InternalActionClient $bot): int
    {
        $discordId = (string) $this->option('discord-id');
        $roleKey = (string) $this->option('role-key');
        $channelKey = (string) $this->option('channel-key');
        $eventKey = (string) ($this->option('event-key') ?: 'tog470-'.Str::random(12));

        foreach (['discord-id' => $discordId, 'role-key' => $roleKey, 'channel-key' => $channelKey] as $name => $value) {
            if ($value === '') {
                $this->error("--{$name} is required. See the header of ".static::class.'.');

                return 2;
            }
        }

        $url = config('services.bot.url');
        $this->line('internal actions smoke (TOG-470)');
        $this->line('  target  '.(is_string($url) && $url !== '' ? $url : '(unset)'));
        $this->line('  key id  '.(is_string($id = config('services.bot.key_id')) && $id !== '' ? $id : '(unset)'));
        $this->newLine();

        // Checked once here rather than discovered per action. The job absorbs a
        // misconfiguration into fail(), which is a no-op when handle() is called
        // inline — so without this the run would report three checks that "did
        // not complete" and exit 1, where the honest answer is exit 2.
        try {
            $bot->assertConfigured();
        } catch (BotNotConfiguredException $e) {
            $this->error($e->getMessage());

            return 2;
        }

        try {
            // -- role.assign — natural idempotency, no key ----------------------
            // Sent through the shipped job, because the job is half of what this
            // command exists to exercise.
            $this->line('role.assign');
            $role = $this->queued(new RoleAssignment($discordId, $roleKey));
            $this->check('role.assign is ok', $this->ok($role), $this->describe($role));

            // -- announcement.post — needs key ---------------------------------
            $this->line('announcement.post');
            $announcement = new Announcement($channelKey, 'TOG-470 smoke run. Ignore. '.now()->toIso8601String());

            $job = new CallInternalAction($announcement);
            $first = $this->runJob($job);
            $this->check('announcement.post is ok', $this->ok($first), $this->describe($first));

            // -- the retry that must NOT post a second announcement -------------
            // §1's "the one that will bite you": same Idempotency-Key, fresh
            // nonce. Re-running *this job instance* is what a real retry is, and
            // it is the check that separates a caller that retries safely from
            // one that double-posts to a live server. Dispatching a second job
            // would mint a second key and correctly post twice.
            $this->line('announcement.post retried with the same idempotency key');
            $replay = $this->runJob($job);
            $this->check('retry is ok', $this->ok($replay), $this->describe($replay));
            $this->check(
                'retry is flagged Idempotent-Replay',
                $replay instanceof AnnouncementResult && $replay->replayed,
                'replayed='.($replay instanceof AnnouncementResult && $replay->replayed ? 'true' : 'false'),
            );
            // §3: the message id comes back on a replay too. Same id both times
            // is the proof that nothing posted twice.
            $firstId = $first instanceof AnnouncementResult ? $first->messageId : null;
            $replayId = $replay instanceof AnnouncementResult ? $replay->messageId : null;
            $this->check(
                'retry returns the original message_id',
                $firstId !== null && $firstId === $replayId,
                'first='.($firstId ?? '(none)').' retry='.($replayId ?? '(none)'),
            );

            // -- event.upsert — needs key --------------------------------------
            // Called directly: its shipped job is SyncEventToDiscord, which reads
            // an Event row out of the database and would make this command need
            // one. The client is the part under test either way.
            $this->line('event.upsert');
            $event = $this->attempt(fn (): EventUpsertResult|InternalActionFailure => $bot->upsertEvent(
                new EventUpsert(
                    eventKey: $eventKey,
                    name: 'TOG-470 smoke event',
                    startsAt: now()->addDay(),
                    endsAt: now()->addDay()->addHour(),
                    location: 'Smoke run - ignore',
                    description: 'Created by php artisan bot:internal-action-smoke',
                ),
                InternalActionClient::newIdempotencyKey(),
            ), 'event.upsert');
            $this->check('event.upsert is ok', $this->ok($event), $this->describe($event));
        } catch (BotNotConfiguredException $e) {
            // Its own exit code rather than three identical failed checks. This
            // is the expected state wherever the staging credentials have not
            // landed yet.
            $this->newLine();
            $this->error($e->getMessage());

            return 2;
        }

        $this->newLine();

        if ($this->failures !== []) {
            foreach ($this->failures as $failure) {
                $this->line("  FAIL {$failure}");
            }
            $this->error(count($this->failures).' check(s) failed. NEEDS WORK');

            return 1;
        }

        $this->info('PASS');

        return 0;
    }

    /** Run one action through the shipped job, on a fresh job instance. */
    private function queued(RoleAssignment|Announcement $action): RoleAssignResult|AnnouncementResult|SettingWriteResult|InternalActionFailure|null
    {
        return $this->runJob(new CallInternalAction($action));
    }

    /**
     * Call `handle()` inline rather than dispatching.
     *
     * A worker reacts to `fail()` by writing `failed_jobs` and moving on, which
     * for a command that owes its caller an exit code would be a silent success.
     * Inline, the outcome comes back on the job itself, and the queue's
     * release/fail behaviour stays covered by the unit tests where it can be
     * asserted properly. No retries here either: a check that only passes on the
     * third attempt has not passed.
     */
    private function runJob(CallInternalAction $job): RoleAssignResult|AnnouncementResult|SettingWriteResult|InternalActionFailure|null
    {
        return $this->attempt(function () use ($job) {
            $job->handle(app(InternalActionClient::class));

            return $job->lastResult;
        }, $job->actionName());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T|null
     *
     * @throws BotNotConfiguredException
     */
    private function attempt(callable $call, string $action): mixed
    {
        try {
            return $call();
        } catch (BotNotConfiguredException $e) {
            // Rethrown, not counted: it is exit code 2, not a failed check.
            throw $e;
        } catch (Throwable $e) {
            $this->failures[] = $action.' threw '.$e::class.': '.$e->getMessage();

            return null;
        }
    }

    private function ok(mixed $result): bool
    {
        return $result !== null && ! $result instanceof InternalActionFailure;
    }

    private function describe(mixed $result): string
    {
        if ($result === null) {
            return 'no result — the call did not complete';
        }

        if ($result instanceof InternalActionFailure) {
            return sprintf(
                'refused %d %s retryable=%s request_id=%s',
                $result->status,
                $result->code,
                $result->retryable ? 'true' : 'false',
                $result->requestId,
            );
        }

        return match (true) {
            $result instanceof RoleAssignResult => 'outcome='.$result->outcome->value.' request_id='.$result->requestId,
            $result instanceof AnnouncementResult => 'message_id='.$result->messageId
                .' replayed='.($result->replayed ? 'true' : 'false').' request_id='.$result->requestId,
            $result instanceof EventUpsertResult => 'outcome='.$result->outcome->value
                .' event_id='.$result->discordEventId.' request_id='.$result->requestId,
            default => 'ok',
        };
    }

    private function check(string $label, bool $ok, string $detail): void
    {
        $this->line(sprintf('  %s  %s  %s', $ok ? '<info>PASS</info>' : '<error>FAIL</error>', $label, $detail));

        if (! $ok) {
            $this->failures[] = "{$label}: {$detail}";
        }
    }
}
