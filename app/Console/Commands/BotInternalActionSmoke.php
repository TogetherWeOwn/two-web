<?php

namespace App\Console\Commands;

use App\Jobs\CallInternalAction;
use App\Services\Bot\InternalAction;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionMisconfigured;
use App\Services\Bot\InternalActionResult;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drives all three live actions against a *running* bot, from the queued job
 * this application actually ships (TOG-470).
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
 * the *website's* job, signer and retry logic work against a real endpoint.
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

    protected $description = 'Call role.assign, announcement.post and event.upsert on the bot, through the queued job.';

    /** @var list<string> */
    private array $failures = [];

    public function handle(InternalActionClient $client): int
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
        $this->line('  target  '.(is_string($url) ? $url : '(unset)'));
        $this->line('  key id  '.(is_string(config('services.bot.key_id')) ? config('services.bot.key_id') : '(unset)'));
        $this->newLine();

        // Checked here rather than discovered per action. The job absorbs a
        // misconfiguration into fail() on purpose — so that a worker does not
        // retry a blank secret four more times — which means it never surfaces
        // through attempt(), and this command owes its caller exit code 2
        // rather than three identical failed checks. That is the expected state
        // wherever the staging credentials have not landed yet (TWO-21, TWO-11).
        try {
            $client->assertConfigured();
        } catch (InternalActionMisconfigured $e) {
            $this->error($e->getMessage());

            return 2;
        }

        try {
            // -- role.assign — natural idempotency, no key ----------------------
            $this->line('role.assign');
            $role = $this->attempt($client, InternalAction::roleAssign($discordId, $roleKey));
            $this->check('role.assign is ok', $role !== null && $role->ok, $this->describe($role));

            // -- announcement.post — needs key ---------------------------------
            $this->line('announcement.post');
            $body = 'TOG-470 smoke run. Ignore. '.now()->toIso8601String();
            $announcement = InternalAction::announcementPost($channelKey, $body);
            $idempotencyKey = (string) Str::uuid();

            $first = $this->attempt($client, $announcement, $idempotencyKey);
            $this->check('announcement.post is ok', $first !== null && $first->ok, $this->describe($first));

            // -- the retry that must NOT post a second announcement -------------
            // Same Idempotency-Key, fresh nonce — §1's "the one that will bite
            // you". This is the check that separates a caller that retries
            // safely from one that double-posts to a live server.
            $this->line('announcement.post retried with the same idempotency key');
            $replay = $this->attempt($client, $announcement, $idempotencyKey);
            $this->check('retry is ok', $replay !== null && $replay->ok, $this->describe($replay));
            $this->check(
                'retry is flagged Idempotent-Replay',
                $replay !== null && $replay->replayed,
                'replay='.($replay?->replayed ? 'true' : 'false'),
            );
            // §3: the message id comes back on a replay too, so a retry still
            // tells you *which* message you have. Same id both times is the
            // proof that nothing posted twice.
            $firstId = $this->messageId($first);
            $replayId = $this->messageId($replay);
            $this->check(
                'retry returns the original message_id',
                $firstId !== null && $firstId === $replayId,
                'first='.($firstId ?? '(none)').' retry='.($replayId ?? '(none)'),
            );

            // -- event.upsert — needs key --------------------------------------
            $this->line('event.upsert');
            $event = $this->attempt($client, InternalAction::eventUpsert(
                eventKey: $eventKey,
                name: 'TOG-470 smoke event',
                startsAt: now()->addDay()->toDateTimeImmutable(),
                endsAt: now()->addDay()->addHour()->toDateTimeImmutable(),
                location: 'Smoke run - ignore',
                description: 'Created by php artisan bot:internal-action-smoke',
            ));
            $this->check('event.upsert is ok', $event !== null && $event->ok, $this->describe($event));
        } catch (InternalActionMisconfigured $e) {
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

    /**
     * Run one action through the shipped job.
     *
     * `handle()` is called inline rather than dispatched on purpose. A worker
     * reacts to `fail()` by writing `failed_jobs` and moving on, which for a
     * command that has to answer with an exit code would mean a silent success.
     * Calling it here means the outcome comes back on the job itself, and the
     * queue's release/fail behaviour stays covered by
     * tests/Unit/Bot/CallInternalActionTest.php where it can be asserted
     * properly. No retries here either — this is a smoke test and a check that
     * only passes on the third attempt has not passed.
     *
     * @throws InternalActionMisconfigured
     */
    private function attempt(InternalActionClient $client, InternalAction $action, ?string $idempotencyKey = null): ?InternalActionResult
    {
        $job = new CallInternalAction($action, $idempotencyKey);

        try {
            $job->handle($client);
        } catch (InternalActionMisconfigured $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->failures[] = $action->name.' threw '.$e::class.': '.$e->getMessage();

            return null;
        }

        return $job->lastResult;
    }

    private function check(string $label, bool $ok, string $detail): void
    {
        $this->line(sprintf('  %s  %s  %s', $ok ? '<info>PASS</info>' : '<error>FAIL</error>', $label, $detail));

        if (! $ok) {
            $this->failures[] = "{$label}: {$detail}";
        }
    }

    private function describe(?InternalActionResult $result): string
    {
        return $result?->summary() ?? 'no result — the call did not complete';
    }

    private function messageId(?InternalActionResult $result): ?string
    {
        $id = $result?->result['message_id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
