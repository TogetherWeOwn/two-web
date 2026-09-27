<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

const JOIN_BOT_URL = 'http://bot.internal:3001';
const JOIN_ENDPOINT = JOIN_BOT_URL.'/internal/actions';
const JOIN_SECRET = 'test-shared-secret-that-is-long-enough-32';
const JOIN_TOKEN = 'member-live-oauth-token';
const JOIN_ID = '111222333444555666';

beforeEach(function () {
    config([
        'services.discord.client_id' => 'test-client-id',
        'services.discord.client_secret' => 'test-client-secret',
        'services.discord.invite_url' => 'https://discord.gg/testinvite',
        'services.bot.url' => JOIN_BOT_URL,
        'services.bot.secret' => JOIN_SECRET,
        'services.bot.key_id' => 'web-test',
        'services.bot.timeout' => 2,
    ]);
});

function joinDiscordUser(): SocialiteUser
{
    $user = new SocialiteUser;
    $user->setRaw([
        'id' => JOIN_ID,
        'username' => 'wren',
        'global_name' => 'Wren',
        'avatar' => 'abc123',
    ])->map([
        'id' => JOIN_ID,
        'nickname' => 'wren',
        'name' => 'wren',
        'avatar' => 'https://cdn.discordapp.com/avatars/'.JOIN_ID.'/abc123.jpg',
    ]);
    $user->token = JOIN_TOKEN;

    return $user;
}

function stubJoinProvider(): void
{
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(joinDiscordUser());
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

it('asks for exactly identify and guilds.join', function () {
    $response = $this->get(route('join.redirect'));

    $response->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect(explode(' ', $query['scope']))->toEqualCanonicalizing(['identify', 'guilds.join'])
        ->and($query['redirect_uri'])->toBe(route('join.callback'));
});

it('adds and signs in the member before landing on the profile', function () {
    stubJoinProvider();
    Http::fake([JOIN_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'added'],
        'request_id' => '01JTESTREQUESTID',
    ], 200)]);

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('profile'))
        ->assertSessionHas('join_result', 'added');

    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user);

    // New rows still land non-moderator via the column default; login sets
    // the flag from Discord roles on the next sign-in.
    expect($user->is_moderator)->toBeFalse();

    Http::assertSent(function (ClientRequest $request) {
        $payload = json_decode($request->body(), true);

        return $request->url() === JOIN_ENDPOINT
            && $payload === [
                'action' => 'guild.add_member',
                'discord_id' => JOIN_ID,
                'access_token' => JOIN_TOKEN,
            ]
            && $request->header('Idempotency-Key') === [];
    });
});

it('does not demote a returning moderator on re-join', function () {
    $existing = User::factory()->moderator()->create(['discord_id' => JOIN_ID]);

    stubJoinProvider();
    Http::fake([JOIN_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'already_member'],
        'request_id' => '01JMODKEPT',
    ], 200)]);

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('profile'))
        ->assertSessionHas('join_result', 'already_member');

    expect($existing->fresh()->is_moderator)->toBeTrue()
        ->and(User::query()->where('discord_id', JOIN_ID)->count())->toBe(1);
});

it('falls back to the invite without storing the token when the bot is down', function () {
    stubJoinProvider();
    Http::fake(fn () => throw new ConnectionException('failed sending '.JOIN_TOKEN));

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'unavailable');

    expect(json_encode(session()->all()))->not->toContain(JOIN_TOKEN);

    $this->get(route('join'))
        ->assertOk()
        ->assertSee('data-testid="invite-link"', escape: false)
        ->assertSee('https://discord.gg/testinvite', escape: false);
});

it('carries a validated source through the OAuth state and logs it with the outcome', function () {
    $this->get(route('join.redirect', ['source' => 'web:homepage']))->assertRedirect();

    expect(session('join_source'))->toBe('web:homepage');

    stubJoinProvider();
    Http::fake([JOIN_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'already_member'],
        'request_id' => '01JATTRIBUTED',
    ], 200)]);
    Log::spy();

    $this->get('/join/callback?code=good&state=x')->assertRedirect(route('profile'));

    Log::shouldHaveReceived('info')->with('One-click join succeeded.', [
        'source' => 'web:homepage',
        'outcome' => 'already_member',
        'request_id' => '01JATTRIBUTED',
    ])->once();
    expect(session('join_source'))->toBeNull();
});

// ---------------------------------------------------------------------------
// The error-param path. Discord sends the member back with `error` instead of
// a code — most often `access_denied` after pressing Cancel. That renders the
// recovery page directly: what happened, one button to try again. Discord's
// own error_description is never echoed back.
//
// No Socialite stub here on purpose: the callback must return before any
// token exchange is attempted, so an unstubbed Socialite would error if the
// controller tried to call Discord.
// ---------------------------------------------------------------------------

it('shows the recovery page when a member says no on the Discord approval screen', function () {
    $response = $this->get('/join/callback?error=access_denied&error_description=The+user+denied+access');

    $response->assertOk()
        ->assertSee(__('join.recovery_denied'), escape: false)
        ->assertSeeHtml('data-testid="oauth-recovery"')
        ->assertSeeHtml('data-testid="oauth-recovery-retry"')
        ->assertSee(route('join.redirect'), escape: false);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('shows the recovery page with the generic message for any other OAuth error', function () {
    $response = $this->get('/join/callback?error=server_error&error_description=Something+broke+over+there');

    $response->assertOk()
        ->assertSee(__('join.recovery_error'), escape: false)
        ->assertSeeHtml('data-testid="oauth-recovery"')
        ->assertSeeHtml('data-testid="oauth-recovery-retry"')
        ->assertDontSee('Something broke over there', escape: false);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// The Discord-is-down path (TOG-5605). The token exchange throws — Discord
// refused the connection, timed out, or rejected a stale code — and the
// callback answers with the recovery page at 503: what happened, one button
// to try again. No 500, no trace, no token in the log; the attempt is logged
// with JoinOutcome=error so the funnel stays countable.
// ---------------------------------------------------------------------------

function stubFailingJoinProvider(Throwable $throws): void
{
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow($throws);
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

it('serves the retry page at 503 when Discord does not answer the token exchange', function () {
    stubFailingJoinProvider(new ConnectionException('Discord is down: '.JOIN_TOKEN));
    Log::spy();

    $response = $this->get('/join/callback?code=good&state=x');

    $response->assertServiceUnavailable()
        ->assertSee(__('join.recovery_discord_down_title'), escape: false)
        ->assertSee(__('join.recovery_discord_down'), escape: false)
        ->assertSeeHtml('data-testid="oauth-recovery"')
        ->assertSeeHtml('data-testid="oauth-recovery-retry"')
        ->assertSee(route('join.redirect'), escape: false)
        ->assertDontSee('Discord is down')
        ->assertDontSee(JOIN_TOKEN);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    // Exact-args match: this pins that the log carries the class name, the
    // source and the error outcome — and nothing else, in particular no
    // exception message that could quote the failed request's token.
    Log::shouldHaveReceived('warning')->with('Discord token exchange failed on the join journey.', [
        'exception' => ConnectionException::class,
        'source' => null,
        'outcome' => 'error',
    ])->once();
});

it('serves the same retry page for a stale or replayed approval, with the source attributed', function () {
    $this->get(route('join.redirect', ['source' => 'web:homepage']))->assertRedirect();
    stubFailingJoinProvider(new InvalidStateException);
    Log::spy();

    $response = $this->get('/join/callback?code=stale&state=wrong');

    $response->assertServiceUnavailable()
        ->assertSee(__('join.recovery_discord_down'), escape: false)
        ->assertSeeHtml('data-testid="oauth-recovery-retry"');

    $this->assertGuest();

    Log::shouldHaveReceived('warning')->with('Discord token exchange failed on the join journey.', [
        'exception' => InvalidStateException::class,
        'source' => 'web:homepage',
        'outcome' => 'error',
    ])->once();
    expect(session('join_source'))->toBeNull();
});

it('serves the retry page instead of a 500 when the driver answers with something unreadable', function () {
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(new stdClass);
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
    Log::spy();

    $response = $this->get('/join/callback?code=good&state=x');

    $response->assertServiceUnavailable()
        ->assertSee(__('join.recovery_discord_down'), escape: false)
        ->assertSeeHtml('data-testid="oauth-recovery-retry"');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')->with(
        'Discord driver returned an unexpected user object on the join journey.',
        Mockery::on(fn ($context) => ($context['outcome'] ?? null) === 'error'
            && ($context['exception'] ?? null) === 'stdClass'),
    )->once();
});
