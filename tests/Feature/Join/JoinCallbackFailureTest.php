<?php

namespace Tests\Feature\Join;

use App\Http\Controllers\DiscordInviteController;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Throwable;

// No RefreshDatabase: these callbacks must fail before touching member storage.
final class JoinCallbackFailureTest extends TestCase
{
    private const SECRET = 'oauth-secret-must-not-be-rendered-or-logged';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.discord.invite_url' => 'https://discord.gg/testinvite']);
        Http::preventStrayRequests();
        Http::fake();
        Log::spy();
    }

    #[DataProvider('expiredApprovals')]
    public function test_expired_approval_redirects_to_immediate_retry(Throwable $exception): void
    {
        $this->failProvider($exception);

        $this->withSession(['join_source' => 'web:homepage'])
            ->get('/join/callback?code=stale&state=x')
            ->assertRedirect(route('join'))
            ->assertSessionHas('join_result', 'expired')
            ->assertSessionMissing('join_source');

        $this->get(route('join'))
            ->assertOk()
            ->assertSeeHtml('data-testid="join-result"')
            ->assertSee(__('join.result.expired'), escape: false)
            ->assertSeeHtml('href="'.route('join.redirect').'"')
            ->assertDontSee(__('join.recovery_discord_down_title'))
            ->assertDontSee(self::SECRET);

        $this->assertGuest();
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->with(
            'Discord token exchange failed on the join journey.',
            ['exception' => $exception::class, 'source' => 'web:homepage', 'outcome' => 'expired'],
        );
        $this->assertStringNotContainsString(self::SECRET, json_encode(session()->all()));
    }

    public static function expiredApprovals(): array
    {
        return [
            'lost or replayed OAuth state' => [new InvalidStateException(self::SECRET)],
            'expired authorization code' => [new ClientException(self::SECRET, self::tokenRequest(), new Response(
                400, [], json_encode(['error' => 'invalid_grant', 'error_description' => self::SECRET]),
            ))],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_renders_recovery_without_blame_or_secrets(Throwable $exception): void
    {
        $this->failProvider($exception);

        $this->withSession(['join_source' => 'web:homepage'])
            ->get('/join/callback?code=stale&state=x')
            ->assertServiceUnavailable()
            ->assertSeeHtml('data-testid="oauth-recovery"')
            ->assertSeeHtml('role="alert"')
            ->assertSee(__('join.recovery_discord_down_title'))
            ->assertSee(__('join.recovery_discord_down'))
            ->assertSeeHtml('href="'.route('join.redirect').'"')
            ->assertSeeHtml('data-testid="invite-link"')
            ->assertSeeHtml('href="https://discord.gg/testinvite"')
            ->assertDontSee(__('join.result.expired'))
            ->assertDontSee(self::SECRET)
            ->assertSessionMissing('join_result')
            ->assertSessionMissing('join_source');

        $this->assertGuest();
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->with(
            'Discord token exchange failed on the join journey.',
            ['exception' => $exception::class, 'source' => 'web:homepage', 'outcome' => 'error'],
        );
        $this->assertStringNotContainsString(self::SECRET, json_encode(session()->all()));
    }

    public static function providerFailures(): array
    {
        return [
            'Laravel transport timeout' => [new ConnectionException(self::SECRET)],
            'Socialite Guzzle transport timeout' => [new ConnectException(self::SECRET, self::tokenRequest())],
            'Discord server error is not an expired code' => [new ServerException(self::SECRET, self::tokenRequest(), new Response(
                503, [], '{"error":"invalid_grant"}',
            ))],
            'invalid client configuration' => [new ClientException(self::SECRET, self::tokenRequest(), new Response(
                400, [], '{"error":"invalid_client"}',
            ))],
            'unreadable error response' => [new ClientException(self::SECRET, self::tokenRequest(), new Response(
                400, [], '<html>'.self::SECRET.'</html>',
            ))],
            'non-object error response' => [new ClientException(self::SECRET, self::tokenRequest(), new Response(
                400, [], '"invalid_grant"',
            ))],
            'rate limited' => [new ClientException(self::SECRET, self::tokenRequest(), new Response(429))],
            'unknown provider error' => [new RuntimeException(self::SECRET)],
        ];
    }

    public function test_recovery_uses_static_invite_when_none_is_configured(): void
    {
        config(['services.discord.invite_url' => null]);
        $this->failProvider(new ConnectionException(self::SECRET));

        $this->get('/join/callback?code=stale&state=x')
            ->assertServiceUnavailable()
            ->assertSeeHtml('href="'.DiscordInviteController::FALLBACK_INVITE.'"');
    }

    private function failProvider(Throwable $exception): void
    {
        $provider = Mockery::mock(AbstractProvider::class)->makePartial();
        $provider->shouldReceive('redirectUrl')->once()->with(route('join.callback'))->andReturnSelf();
        $provider->shouldReceive('user')->once()->andThrow($exception);
        Socialite::shouldReceive('driver')->once()->with('discord')->andReturn($provider);
    }

    private static function tokenRequest(): Request
    {
        return new Request('POST', 'https://discord.com/api/oauth2/token');
    }
}
