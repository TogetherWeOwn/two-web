<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

// TOG-7293: exploratory pass over the join denial matrix.
//
// The five degraded paths (OAuth deny, expired code, double-submit,
// already_member re-entry, Discord/bot-down) all behave correctly in
// JoinController::callback — the pass was an all-clear. What was missing was
// pinning tests: the existing suite covers the happy path, denied, expired,
// bot-connection-down and moderator re-join, but nothing pinned a bot
// *refusal* (ok:false), an unconfigured bot on the redirect, an idempotent
// double-submit, an authenticated re-entry, or a Discord-down token exchange.
// This file pins exactly those, so a future regression goes red here.
//
// NOTE: helper/const names are file-prefixed. Pest loads every test file into
// one process, so `stubJoinProvider` (OneClickJoinTest) or `FIXATION_PRE_ID`
// (SessionFixationTest) would fatal on redeclaration here.

const MATRIX_BOT_URL = 'http://bot.internal:3001';
const MATRIX_ENDPOINT = MATRIX_BOT_URL.'/internal/actions';
const MATRIX_SECRET = 'test-shared-secret-that-is-long-enough-32';
const MATRIX_TOKEN = 'member-live-oauth-token';
const MATRIX_ID = '111222333444555666';

beforeEach(function () {
    config([
        'services.discord.client_id' => 'test-client-id',
        'services.discord.client_secret' => 'test-client-secret',
        'services.discord.invite_url' => 'https://discord.gg/testinvite',
        'services.bot.url' => MATRIX_BOT_URL,
        'services.bot.secret' => MATRIX_SECRET,
        'services.bot.key_id' => 'web-test',
        'services.bot.timeout' => 2,
    ]);
});

function matrixJoinUser(): SocialiteUser
{
    $user = new SocialiteUser;
    $user->setRaw([
        'id' => MATRIX_ID,
        'username' => 'wren',
        'global_name' => 'Wren',
        'avatar' => 'abc123',
    ])->map([
        'id' => MATRIX_ID,
        'nickname' => 'wren',
        'name' => 'wren',
        'avatar' => 'https://cdn.discordapp.com/avatars/'.MATRIX_ID.'/abc123.jpg',
    ]);
    $user->token = MATRIX_TOKEN;

    return $user;
}

function stubMatrixJoinProvider(): void
{
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(matrixJoinUser());
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

it('falls back to the invite when the bot refuses, without signing anyone in', function () {
    // Discord-down at the bot stage: the bot answers 502 discord_unavailable.
    // The callback must not 500, must not create a user, must not sign in —
    // the member keeps the invite fallback.
    stubMatrixJoinProvider();
    Http::fake([MATRIX_ENDPOINT => Http::response([
        'ok' => false,
        'error' => ['code' => 'discord_unavailable', 'message' => 'discord errored', 'retryable' => true],
        'request_id' => '01JMATRIXREFUSED',
    ], 502)]);

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'unavailable');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    $this->get(route('join'))
        ->assertOk()
        ->assertSee('data-testid="invite-link"', escape: false)
        ->assertSee('https://discord.gg/testinvite', escape: false);
});

it('sends an unconfigured bot straight to unavailable without touching Discord', function () {
    // No secret, no handoff: the redirect must not leak the OAuth URL.
    config(['services.bot.secret' => '']);

    $response = $this->get(route('join.redirect'));

    $response->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'unavailable');
    expect((string) $response->headers->get('Location'))->not->toContain('discord.com');
    Http::assertNothingSent();
});

it('treats a double-submitted callback as one idempotent join', function () {
    // Back-button / double-click replay with the mock provider: both land on
    // the profile, still exactly one row, still signed in as that member.
    stubMatrixJoinProvider();
    Http::fake([MATRIX_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'added'],
        'request_id' => '01JMATRIXDOUBLE',
    ], 200)]);

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('profile'))
        ->assertSessionHas('join_result', 'added');

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('profile'))
        ->assertSessionHas('join_result', 'added');

    expect(User::query()->where('discord_id', MATRIX_ID)->count())->toBe(1);
    $this->assertAuthenticatedAs(User::query()->sole());
});

it('keeps an authenticated member signed in on already_member re-entry', function () {
    $existing = User::factory()->create(['discord_id' => MATRIX_ID]);

    stubMatrixJoinProvider();
    Http::fake([MATRIX_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'already_member'],
        'request_id' => '01JMATRIXREENTRY',
    ], 200)]);

    $this->actingAs($existing)
        ->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('profile'))
        ->assertSessionHas('join_result', 'already_member');

    $this->assertAuthenticatedAs($existing);
    expect(User::query()->where('discord_id', MATRIX_ID)->count())->toBe(1);
});

it('maps a Discord-down token exchange to recovery without reaching the bot', function () {
    // A provider timeout is not an expired approval. Offer a retry and invite
    // without signing anyone in or handing a token to the bot.
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new ConnectionException('discord.com:443 timeout'));
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    $this->get('/join/callback?code=good&state=x')
        ->assertServiceUnavailable()
        ->assertViewIs('oauth.recovery')
        ->assertSee(__('join.recovery_discord_down'))
        ->assertSee('href="'.route('join.redirect').'"', escape: false)
        ->assertSee('href="https://discord.gg/testinvite"', escape: false)
        ->assertSessionMissing('join_result');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
    $this->assertDatabaseCount('rsvps', 0);
    Http::assertNothingSent();
});

it('renders the recovery page without touching the bot or the user table on an OAuth deny', function () {
    // No Socialite stub: the callback returns before any token exchange is
    // attempted. The member sees what happened, one button to retry, and the
    // static invite fallback — same page the Discord-down path renders.
    $response = $this->get('/join/callback?error=access_denied&error_description=The+user+denied+access&state=x');

    $response->assertOk()
        ->assertSee(__('join.recovery_denied'), escape: false)
        ->assertSeeHtml('data-testid="oauth-recovery"')
        ->assertSeeHtml('role="alert"')
        ->assertSeeHtml('data-testid="oauth-recovery-retry"')
        ->assertSee(route('join.redirect'), escape: false)
        ->assertSee('href="https://discord.gg/testinvite"', escape: false)
        ->assertDontSee('The user denied access', escape: false)
        ->assertSessionMissing('join_result');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('renders the recovery page with the generic message for any other OAuth error', function () {
    // `error=server_error` and friends: Discord refused the approval for its
    // own reasons. Same page, same retry button, different sentence — and
    // Discord's own error_description is never echoed back.
    $response = $this->get('/join/callback?error=server_error&error_description=Something+broke+over+there&state=x');

    $response->assertOk()
        ->assertSee(__('join.recovery_error'), escape: false)
        ->assertSeeHtml('data-testid="oauth-recovery"')
        ->assertSeeHtml('data-testid="oauth-recovery-retry"')
        ->assertDontSee('Something broke over there', escape: false)
        ->assertSessionMissing('join_result');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
    Http::assertNothingSent();
});
