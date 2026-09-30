<?php

namespace Tests\Feature\Console;

use App\Console\Commands\BotInternalActionSmoke;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// No RefreshDatabase: prerequisite failures must return before any action runs.
final class BotSmokePrerequisitesTest extends TestCase
{
    private const OPTIONS = [
        '--discord-id' => '900000000000009999',
        '--role-key' => 'test-role',
        '--channel-key' => 'test-throwaway',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bot.url' => 'https://bot.example.test',
            'services.bot.secret' => 'synthetic-smoke-secret-at-least-32-characters',
            'services.bot.key_id' => 'smoke-test',
            'services.bot.timeout' => 1,
        ]);
        Http::preventStrayRequests();
        Http::fake();
    }

    #[DataProvider('requiredOptions')]
    public function test_missing_option_exits_before_sending_a_request(string $option): void
    {
        $options = self::OPTIONS;
        unset($options[$option]);

        $this->artisan('bot:internal-action-smoke', $options)
            ->expectsOutput("{$option} is required. See the header of ".BotInternalActionSmoke::class.'.')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    public static function requiredOptions(): array
    {
        return [
            'discord id' => ['--discord-id'],
            'role key' => ['--role-key'],
            'channel key' => ['--channel-key'],
        ];
    }

    #[DataProvider('missingConfiguration')]
    public function test_missing_configuration_exits_before_sending_a_request(string $key, string $variable, ?string $value): void
    {
        config()->set("services.bot.{$key}", $value);

        $this->artisan('bot:internal-action-smoke', self::OPTIONS)
            ->expectsOutput("The bot internal action endpoint is not configured: {$variable} is empty.")
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    public static function missingConfiguration(): array
    {
        return [
            'unset endpoint URL' => ['url', 'BOT_ENDPOINT_URL', null],
            'blank endpoint URL' => ['url', 'BOT_ENDPOINT_URL', ''],
            'unset shared secret' => ['secret', 'BOT_SHARED_SECRET', null],
            'blank shared secret' => ['secret', 'BOT_SHARED_SECRET', ''],
            'unset key id' => ['key_id', 'BOT_KEY_ID', null],
            'blank key id' => ['key_id', 'BOT_KEY_ID', ''],
        ];
    }
}
