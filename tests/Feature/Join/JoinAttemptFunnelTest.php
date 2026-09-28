<?php

use App\Enums\JoinOutcome;
use App\Models\JoinAttempt;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

// TOG-5617: every terminal path of the one-click join writes exactly one
// join_attempts row with the mapped outcome — the queryable funnel behind the
// admin JoinFunnelStats widget. Log calls stay byte-identical (pinned in
// OneClickJoinTest); these tests pin the rows next to them.
//
// NOTE: helper/const names are file-prefixed. Pest loads every test file into
// one process, so `stubJoinProvider` (OneClickJoinTest) would fatal here.

const FUNNEL_BOT_URL = 'http://bot.internal:3001';
const FUNNEL_ENDPOINT = FUNNEL_BOT_URL.'/internal/actions';
const FUNNEL_SECRET = 'test-shared-secret-that-is-long-enough-32';
const FUNNEL_TOKEN = 'member-live-oauth-token';
const FUNNEL_ID = '111222333444555666';

beforeEach(function () {
    config([
        'services.discord.client_id' => 'test-client-id',
        'services.discord.client_secret' => 'test-client-secret',
        'services.discord.invite_url' => 'https://discord.gg/testinvite',
        'services.bot.url' => FUNNEL_BOT_URL,
        'services.bot.secret' => FUNNEL_SECRET,
        'services.bot.key_id' => 'web-test',
        'services.bot.timeout' => 2,
    ]);
    Cache::flush();
});

function funnelJoinUser(): SocialiteUser
{
    $user = new SocialiteUser;
    $user->setRaw([
        'id' => FUNNEL_ID,
        'username' => 'wren',
        'global_name' => 'Wren',
        'avatar' => 'abc123',
    ])->map([
        'id' => FUNNEL_ID,
        'nickname' => 'wren',
        'name' => 'wren',
        'avatar' => 'https://cdn.discordapp.com/avatars/'.FUNNEL_ID.'/abc123.jpg',
    ]);
    $user->token = FUNNEL_TOKEN;

    return $user;
}

function stubFunnelJoinProvider(): void
{
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(funnelJoinUser());
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

it('records Added with request_id and discord_id on a successful join', function () {
    stubFunnelJoinProvider();
    Http::fake([FUNNEL_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'added'],
        'request_id' => '01JFUNNELADDED',
    ], 200)]);

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('profile'))
        ->assertSessionHas('join_result', 'added');

    $attempt = JoinAttempt::query()->sole();
    expect($attempt->outcome)->toBe(JoinOutcome::Added)
        ->and($attempt->request_id)->toBe('01JFUNNELADDED')
        ->and($attempt->discord_id)->toBe(FUNNEL_ID);
});

it('records AlreadyMember on a re-join', function () {
    stubFunnelJoinProvider();
    Http::fake([FUNNEL_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'already_member'],
        'request_id' => '01JFUNNELREJOIN',
    ], 200)]);

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('profile'))
        ->assertSessionHas('join_result', 'already_member');

    $attempt = JoinAttempt::query()->sole();
    expect($attempt->outcome)->toBe(JoinOutcome::AlreadyMember)
        ->and($attempt->request_id)->toBe('01JFUNNELREJOIN')
        ->and($attempt->discord_id)->toBe(FUNNEL_ID);
});

it('records Denied without touching the bot when Discord reports an error', function () {
    $this->get('/join/callback?error=access_denied&error_description=the+user+denied+access&state=x')
        ->assertOk()
        ->assertSee(__('join.recovery_denied'), escape: false)
        ->assertSeeHtml('data-testid="oauth-recovery"')
        ->assertSessionMissing('join_result');

    $attempt = JoinAttempt::query()->sole();
    expect($attempt->outcome)->toBe(JoinOutcome::Denied)
        ->and($attempt->request_id)->toBeNull()
        ->and($attempt->discord_id)->toBeNull();

    Http::assertNothingSent();
});

it('records Error when Discord is down during the token exchange', function () {
    // Discord itself unreachable: the 503 recovery page (expired/down split),
    // still an Error row per the TOG-5617 mapping — the member did nothing
    // wrong, the funnel just records that the exchange failed.
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new ConnectionException('discord.com:443 timeout'));
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    $this->get('/join/callback?code=good&state=x')
        ->assertServiceUnavailable();

    $attempt = JoinAttempt::query()->sole();
    expect($attempt->outcome)->toBe(JoinOutcome::Error)
        ->and($attempt->discord_id)->toBeNull();

    Http::assertNothingSent();
});

it('records Error when the approval expired', function () {
    // Stale or replayed OAuth state: the expired banner with an immediate
    // retry — and the same Error row, since the token exchange threw.
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    $this->get('/join/callback?code=stale&state=x')
        ->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'expired');

    $attempt = JoinAttempt::query()->sole();
    expect($attempt->outcome)->toBe(JoinOutcome::Error)
        ->and($attempt->discord_id)->toBeNull();

    Http::assertNothingSent();
});

it('records Degraded when the bot is unreachable', function () {
    stubFunnelJoinProvider();
    Http::fake(fn () => throw new ConnectionException('failed sending '.FUNNEL_TOKEN));

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'unavailable');

    $attempt = JoinAttempt::query()->sole();
    expect($attempt->outcome)->toBe(JoinOutcome::Degraded)
        ->and($attempt->discord_id)->toBe(FUNNEL_ID);
});

it('records Degraded when the bot refuses', function () {
    stubFunnelJoinProvider();
    Http::fake([FUNNEL_ENDPOINT => Http::response([
        'ok' => false,
        'error' => ['code' => 'discord_unavailable', 'message' => 'discord errored', 'retryable' => true],
        'request_id' => '01JFUNNELREFUSED',
    ], 502)]);

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'unavailable');

    $attempt = JoinAttempt::query()->sole();
    expect($attempt->outcome)->toBe(JoinOutcome::Degraded)
        ->and($attempt->request_id)->toBe('01JFUNNELREFUSED')
        ->and($attempt->discord_id)->toBe(FUNNEL_ID);
});

it('records Degraded on the redirect when the bot is not configured', function () {
    config(['services.bot.secret' => '']);

    $this->get(route('join.redirect'))
        ->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'unavailable');

    $attempt = JoinAttempt::query()->sole();
    expect($attempt->outcome)->toBe(JoinOutcome::Degraded);

    Http::assertNothingSent();
});

it('carries the source onto the success row', function () {
    $this->get(route('join.redirect', ['source' => 'web:homepage']))->assertRedirect();

    stubFunnelJoinProvider();
    Http::fake([FUNNEL_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'already_member'],
        'request_id' => '01JFUNNELSOURCE',
    ], 200)]);

    $this->get('/join/callback?code=good&state=x')->assertRedirect(route('profile'));

    expect(JoinAttempt::query()->sole()->source)->toBe('web:homepage');
});

it('never stores the token, an exception message, or the error_description', function () {
    $token = 'funnel-token-that-must-never-be-stored';
    $description = 'user denied the thing with specifics';

    // Path 1: bot unreachable with the token in the failure message.
    $user = new SocialiteUser;
    $user->setRaw(['id' => '111'])->map(['id' => '111', 'nickname' => 'wren']);
    $user->token = $token;

    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    Http::fake(fn () => throw new ConnectionException('failed sending '.$token));

    $this->get('/join/callback?code=good&state=x')->assertSessionHas('join_result', 'unavailable');

    // Path 2: OAuth deny carrying an attacker-shaped error_description. The
    // description is never rendered and the error is never stored.
    $this->get('/join/callback?error=access_denied&error_description='.urlencode($description).'&state=x')
        ->assertOk()
        ->assertDontSee($description, escape: false)
        ->assertSessionMissing('join_result');

    $dump = JoinAttempt::query()->get()->toJson();
    expect($dump)->not->toContain($token)
        ->and($dump)->not->toContain($description)
        ->and(JoinAttempt::query()->count())->toBe(2);
});

it('shows the funnel counts to a moderator on /admin', function () {
    JoinAttempt::query()->create(['outcome' => JoinOutcome::Added]);
    JoinAttempt::query()->create(['outcome' => JoinOutcome::Added]);
    JoinAttempt::query()->create(['outcome' => JoinOutcome::AlreadyMember]);
    JoinAttempt::query()->create(['outcome' => JoinOutcome::Denied]);
    JoinAttempt::query()->create(['outcome' => JoinOutcome::Degraded]);

    $moderator = User::factory()->create(['is_moderator' => true]);

    $this->actingAs($moderator)->get('/admin')
        ->assertOk()
        ->assertSee('Join funnel', escape: false)
        ->assertSee('Added', escape: false)
        ->assertSee('Already member', escape: false)
        ->assertSee('Denied', escape: false)
        ->assertSee('Errors + degraded', escape: false);
});

it('answers a plain member on /admin with 403', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)->get('/admin')->assertForbidden();
});
