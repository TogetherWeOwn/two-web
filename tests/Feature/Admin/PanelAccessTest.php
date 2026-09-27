<?php

use App\Models\User;

// The card's own acceptance line: a plain member gets a 403, not a login loop.
//
// These go through HTTP rather than the gate because the thing under test is the
// wiring, not the rule — EventPolicyTest already proves the rule. What can break
// here is the panel forgetting to ask (a resource registered outside the auth
// middleware), or answering a signed-in non-moderator with a redirect back to
// login, which for a user who *is* logged in is a loop with no exit.

it('redirects a guest on /admin toward the Discord login, not a Filament login form', function () {
    $response = $this->get('/admin');

    // The panel deliberately has no ->login(): Discord OAuth is the only
    // identity this site has. A guest must leave /admin unauthenticated —
    // anything else means Filament grew its own login page.
    $response->assertRedirect(route('login'));
});

it('answers a plain member on /admin with 403, not a redirect', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)->get('/admin')->assertForbidden();
});

it('lets a moderator load the panel dashboard', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);

    $this->actingAs($moderator)->get('/admin')->assertOk();
});

// The CISO session-handling bar (TOG-5469), violation V4: the /admin logout
// must be the site logout — Auth::logout + session invalidate() +
// regenerateToken() — not a thinner vendor default. Filament v4.12.8's
// LogoutController performs all three (verified against pinned vendor source),
// and this test pins the wiring from our side: POST-only, session contents
// gone, members-only pages back behind login, exactly like the site /logout
// journey in DiscordLoginTest.
it('signs a moderator out of the panel the way the site signs them out', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);

    $response = $this->actingAs($moderator)
        ->withSession(['cart_of_secrets' => 'still here'])
        ->post(route('filament.admin.auth.logout'));

    $this->assertGuest();

    // Not just "logged out" — the session contents are gone too, the same
    // assertion the site logout test makes.
    $response->assertSessionMissing('cart_of_secrets');

    // And the signed-out visitor is back outside the members-only pages.
    $this->get(route('profile'))->assertRedirect(route('login'));
    $this->get('/admin')->assertRedirect(route('login'));
});

it('will not sign anyone out of the panel over GET', function () {
    $this->get('/admin/logout')->assertMethodNotAllowed();
});

it('drops panel access mid-session when the moderator role is revoked', function () {
    // is_moderator is recomputed at login, but a session can outlive the role.
    // canAccessPanel() reads the current row, so a revoked moderator is out on
    // their next request, not their next login.
    $exModerator = User::factory()->create(['is_moderator' => true]);
    $this->actingAs($exModerator)->get('/admin')->assertOk();

    $exModerator->forceFill(['is_moderator' => false])->save();

    $this->actingAs($exModerator->fresh())->get('/admin')->assertForbidden();
});
