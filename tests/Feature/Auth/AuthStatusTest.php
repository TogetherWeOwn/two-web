<?php

use App\Models\User;

// TOG-8136: the cross-tab sign-out probe. Logout destroys the session
// server-side but a second tab keeps rendering @auth controls until its next
// load; the layout's tab-sync script asks GET auth.status on visibility/focus
// and a `false` reloads the tab into the guest render.
//
// What this pins: the endpoint answers a bare boolean (never a login redirect
// for a logged-out caller, never member-identifying data), it flips with the
// session, it is uncacheable, and the script that asks is emitted on
// authenticated renders only — guests get zero bytes and no probe.

it('tells a signed-out visitor they are signed out', function () {
    $this->get(route('auth.status'))
        ->assertOk()
        ->assertExactJson(['authenticated' => false]);
});

it('tells a signed-in member they are signed in', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('auth.status'))
        ->assertOk()
        ->assertExactJson(['authenticated' => true]);
});

it('flips to signed out after the logout POST', function () {
    // The stale-tab journey in one client: authed, then the logout POST in
    // another tab kills the session, then this tab's probe must see it.
    $this->actingAs(User::factory()->create())
        ->get(route('auth.status'))
        ->assertExactJson(['authenticated' => true]);

    $this->post(route('logout'))->assertRedirect(route('home'));

    $this->get(route('auth.status'))
        ->assertOk()
        ->assertExactJson(['authenticated' => false]);
});

it('never caches the answer', function () {
    // A shared cache keyed on the URL alone could serve one member's `true`
    // to a logged-out tab, which would silence the reload forever.
    $this->actingAs(User::factory()->create())
        ->get(route('auth.status'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('emits the tab-sync script on authenticated pages', function () {
    $authed = $this->actingAs(User::factory()->create())
        ->get(route('profile'))
        ->assertOk()
        ->getContent();

    expect($authed)->toBeString()
        ->and($authed)->toContain('data-testid="auth-tab-sync"')
        // @js() JSON-encodes the URL, so slashes arrive as `\/` — assert the
        // encoded probe path to prove the script points at the real endpoint.
        ->and($authed)->toContain('\\/auth\\/status');
});

it('emits no tab-sync script or probe for guests', function () {
    $guest = $this->get(route('home'))
        ->assertOk()
        ->getContent();

    expect($guest)->toBeString()
        ->and($guest)->not->toContain('data-testid="auth-tab-sync"')
        ->and($guest)->not->toContain('/auth/status');
});
