<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * Branded error pages (TOG-5626). Laravel renders `errors/{code}.blade.php`
 * automatically — no route or handler code exists to unit test, so these tests
 * pin the observable behaviour: status code plus branded body plus the join
 * CTA that keeps a lost visitor in the funnel.
 */

it('renders the branded 404 with a join CTA on an unknown URL', function () {
    $this->get('/nx-9x7q2-zzz')
        ->assertStatus(404)
        ->assertSee(__('errors.not_found_title'), escape: false)
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertSee(route('join'), escape: false);
});

it('renders the branded 500 without leaking the exception', function () {
    // Debug off: with debug on Laravel renders the Symfony trace page instead
    // of the branded view, so the test would pin the wrong page.
    config(['app.debug' => false]);

    Route::get('/_test-boom-5626', function (): void {
        throw new RuntimeException('secret-boom-message-5626');
    });

    $this->get('/_test-boom-5626')
        ->assertStatus(500)
        ->assertSee(__('errors.error_title'), escape: false)
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertSee(route('join'), escape: false)
        ->assertDontSee('secret-boom-message-5626', escape: false);
});

it('renders the branded 503 with the invite CTA during maintenance mode', function () {
    // The CTA points at the invite URL, not route('join'): /join answers 503
    // too while maintenance mode is on, so sending a member there is a dead
    // end. The invite is config-only, so this page works with the DB down.
    $this->artisan('down')->assertSuccessful();

    try {
        $this->get('/join')
            ->assertStatus(503)
            ->assertSee(__('errors.unavailable_title'), escape: false)
            ->assertSee('data-testid="discord-join"', escape: false)
            ->assertSee(config('services.discord.invite_url'), escape: false);
    } finally {
        $this->artisan('up')->assertSuccessful();
    }
});

it('renders the branded 405 with sign-out guidance on GET /logout', function () {
    // Logout is POST-only by design (routes/web.php) so an <img> tag can
    // never sign a member out — TOG-8562. A stale GET link must land on a
    // branded page naming the rule with a link to /profile, where the real
    // Sign out POST form lives, not on the framework-default 405.
    config(['app.debug' => false]);

    // Guest and member take the identical MethodNotAllowed path — /logout
    // sits outside the `auth` group, so no login wall runs first.
    $this->get('/logout')
        ->assertStatus(405)
        ->assertSee(__('errors.method_not_allowed_title'), escape: false)
        ->assertSee(__('errors.method_not_allowed_body'), escape: false)
        ->assertSee('data-testid="error-profile"', escape: false)
        ->assertSee(route('profile'), escape: false);

    $this->actingAs(User::factory()->create())
        ->get('/logout')
        ->assertStatus(405)
        ->assertSee(__('errors.method_not_allowed_title'), escape: false)
        ->assertSee('data-testid="error-profile"', escape: false);
});

it('marks every error page noindex', function () {
    config(['app.debug' => false]);

    Route::get('/_test-boom-5626-noindex', function (): void {
        throw new RuntimeException('boom');
    });

    $this->get('/nx-9x7q2-zzz')->assertSee('noindex', escape: false);
    $this->get('/_test-boom-5626-noindex')->assertSee('noindex', escape: false);
});
