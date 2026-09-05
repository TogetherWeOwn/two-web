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

it('drops panel access mid-session when the moderator role is revoked', function () {
    // is_moderator is recomputed at login, but a session can outlive the role.
    // canAccessPanel() reads the current row, so a revoked moderator is out on
    // their next request, not their next login.
    $exModerator = User::factory()->create(['is_moderator' => true]);
    $this->actingAs($exModerator)->get('/admin')->assertOk();

    $exModerator->forceFill(['is_moderator' => false])->save();

    $this->actingAs($exModerator->fresh())->get('/admin')->assertForbidden();
});
