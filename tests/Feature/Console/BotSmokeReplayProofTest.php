<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// No RefreshDatabase: the smoke command's actions are all intercepted by Http::fake.
final class BotSmokeReplayProofTest extends TestCase
{
    private const MESSAGE_ID = '112233445566778899';

    private const OPTIONS = [
        '--discord-id' => '900000000000009999',
        '--role-key' => 'test-role',
        '--channel-key' => 'test-throwaway',
        '--event-key' => 'test-smoke-replay',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bot.url' => 'https://bot.example.test',
            'services.bot.secret' => bin2hex(random_bytes(32)),
            'services.bot.key_id' => 'smoke-test',
            'services.bot.timeout' => 1,
        ]);
        Http::preventStrayRequests();
    }

    #[DataProvider('unprovenReplays')]
    public function test_successful_retry_without_both_proofs_fails_the_smoke_verdict(
        array $headers,
        string $messageId,
        string $failedProof,
        string $passingProof,
    ): void {
        $this->fakeActions($headers, $messageId);

        $this->assertSame(1, Artisan::call('bot:internal-action-smoke', self::OPTIONS));
        $output = Artisan::output();

        $this->assertStringContainsString($failedProof, $output);
        $this->assertStringContainsString($passingProof, $output);
        $this->assertStringContainsString('1 check(s) failed. NEEDS WORK', $output);
        // Individual checks still pass; only the standalone overall verdict must be absent.
        $this->assertDoesNotMatchRegularExpression('/^PASS\s*$/m', $output);
        $this->assertSuccessfulActionsAndSameAnnouncementKey($output);
    }

    public static function unprovenReplays(): array
    {
        return [
            'missing replay header with the original message id' => [
                [],
                self::MESSAGE_ID,
                'FAIL retry is flagged Idempotent-Replay: replayed=false',
                'PASS  retry returns the original message_id',
            ],
            'replay header with a different message id' => [
                ['Idempotent-Replay' => 'true'],
                '998877665544332211',
                'FAIL retry returns the original message_id: first='.self::MESSAGE_ID.' retry=998877665544332211',
                'PASS  retry is flagged Idempotent-Replay',
            ],
        ];
    }

    public function test_same_message_id_with_replay_header_passes_the_smoke_verdict(): void
    {
        $this->fakeActions(['Idempotent-Replay' => 'true'], self::MESSAGE_ID);

        $this->assertSame(0, Artisan::call('bot:internal-action-smoke', self::OPTIONS));
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/^PASS\s*$/m', $output);
        $this->assertStringNotContainsString('FAIL', $output);
        $this->assertStringContainsString('PASS  retry is flagged Idempotent-Replay', $output);
        $this->assertStringContainsString('PASS  retry returns the original message_id', $output);
        $this->assertSuccessfulActionsAndSameAnnouncementKey($output);
    }

    private function fakeActions(array $replayHeaders, string $replayMessageId): void
    {
        Http::fake([
            'https://bot.example.test/internal/actions' => Http::sequence()
                ->push(['ok' => true, 'result' => ['outcome' => 'assigned'], 'request_id' => 'test-role'])
                ->push(['ok' => true, 'result' => ['message_id' => self::MESSAGE_ID], 'request_id' => 'test-first'])
                ->push(['ok' => true, 'result' => ['message_id' => $replayMessageId], 'request_id' => 'test-retry'], 200, $replayHeaders)
                ->push(['ok' => true, 'result' => ['outcome' => 'created', 'event_id' => '123456789012345678'], 'request_id' => 'test-event']),
        ]);
    }

    private function assertSuccessfulActionsAndSameAnnouncementKey(string $output): void
    {
        foreach (['role.assign is ok', 'announcement.post is ok', 'retry is ok', 'event.upsert is ok'] as $check) {
            $this->assertStringContainsString('PASS  '.$check, $output);
        }

        Http::assertSentCount(4);
        $requests = Http::recorded()->map(fn (array $pair) => $pair[0])->all();
        $this->assertSame(
            ['role.assign', 'announcement.post', 'announcement.post', 'event.upsert'],
            array_map(fn ($request) => $request->data()['action'], $requests),
        );
        $key = $requests[1]->header('Idempotency-Key');
        $this->assertCount(1, $key);
        $this->assertTrue(Str::isUuid($key[0]));
        $this->assertSame($key, $requests[2]->header('Idempotency-Key'));
        $this->assertSame($requests[1]->body(), $requests[2]->body());
    }
}
