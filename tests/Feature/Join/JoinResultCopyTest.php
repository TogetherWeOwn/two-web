<?php

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

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
    'denied' => ['denied', 'alert'],
    'expired' => ['expired', 'alert'],
]);

it('pins denied when Discord reports access_denied and renders the banner', function () {
    $this->get('/join/callback?error=access_denied&state=x')
        ->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'denied');

    $copy = __('join.result.denied');
    expect($copy)->not->toBe('join.result.denied');

    $this->get(route('join'))
        ->assertOk()
        ->assertSeeHtml('data-testid="join-result"')
        ->assertSeeHtml('role="alert"')
        ->assertSee($copy, escape: false);
});

it('pins expired when the token exchange throws and renders the banner', function () {
    // The Socialite driver is mocked, so no Discord credentials are needed —
    // the throw happens inside `user()` before any network call.
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new RuntimeException('expired code'));
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
