<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
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
