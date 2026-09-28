<?php

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;

it('renders a human sentence for every join result code we can emit', function (string $code, string $role) {
    // Join-side parity with the login banner pin
    // (DiscordLoginTest: "renders a human sentence for every error code we can emit").
    // A deleted or renamed `join.result.*` key must go red here, not ship a raw
    // translation key to members. `__()` returns the key itself when missing, so
    // assert the key resolves AND the page shows the sentence, never the key.
    $copy = __('join.result.'.$code);

    expect($copy)->not->toBe('join.result.'.$code);

    $this->withSession(['join_result' => $code])
        ->get(route('join'))
        ->assertOk()
        ->assertSeeHtml('data-testid="join-result"')
        ->assertSeeHtml('role="'.$role.'"')
        ->assertSee($copy, escape: false)
        ->assertDontSee('join.result.', escape: false);
})->with([
    'added' => ['added', 'status'],
    'already a member' => ['already_member', 'status'],
    'unavailable' => ['unavailable', 'alert'],
    'expired' => ['expired', 'alert'],
]);

it('pins the recovery page when Discord reports access_denied, never the banner', function () {
    // No Socialite stub here on purpose — the callback returns before any
    // token exchange is attempted, so an unstubbed Socialite would error if
    // the controller tried to call Discord.
    $response = $this->get('/join/callback?error=access_denied&error_description=The+user+denied+access&state=x');

    $copy = __('join.recovery_denied');
    expect($copy)->not->toBe('join.recovery_denied');

    $response->assertOk()
        ->assertSeeHtml('data-testid="oauth-recovery"')
        ->assertSeeHtml('role="alert"')
        ->assertSee($copy, escape: false)
        ->assertSeeHtml('data-testid="oauth-recovery-retry"')
        ->assertSee(route('join.redirect'), escape: false)
        ->assertDontSee('The user denied access', escape: false)
        ->assertSessionMissing('join_result');
});

it('pins the recovery page with the generic message for any other OAuth error', function () {
    $response = $this->get('/join/callback?error=server_error&error_description=Something+broke+over+there&state=x');

    $copy = __('join.recovery_error');
    expect($copy)->not->toBe('join.recovery_error');

    $response->assertOk()
        ->assertSeeHtml('data-testid="oauth-recovery"')
        ->assertSee($copy, escape: false)
        ->assertSeeHtml('data-testid="oauth-recovery-retry"')
        ->assertDontSee('Something broke over there', escape: false)
        ->assertSessionMissing('join_result');
});

it('pins expired when OAuth state is invalid and renders the banner', function () {
    // The Socialite driver is mocked, so no Discord credentials are needed —
    // the throw happens inside `user()` before any network call.
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    $this->get('/join/callback?code=stale&state=x')
        ->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'expired');

    $copy = __('join.result.expired');
    expect($copy)->not->toBe('join.result.expired');

    $this->get(route('join'))
        ->assertOk()
        ->assertSeeHtml('data-testid="join-result"')
        ->assertSeeHtml('role="alert"')
        ->assertSee($copy, escape: false);
});
