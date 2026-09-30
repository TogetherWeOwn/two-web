<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutVite();
});

it('renders a native POST logout form in shared chrome for signed-in members', function (string $page) {
    $url = $page === 'event'
        ? route('events.page', Event::factory()->create(['status' => EventStatus::Published]))
        : $page;

    $response = $this->actingAs(User::factory()->create())->get($url)->assertOk();

    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $forms = $xpath->query('//nav[@data-testid="member-account-controls"]//form');

    expect($forms)->toHaveCount(1);
    $form = $forms->item(0);
    expect($form->getAttribute('method'))->toBe('POST')
        ->and($form->getAttribute('action'))->toBe(route('logout'));

    $tokens = $xpath->query('.//input[@type="hidden" and @name="_token"]', $form);
    expect($tokens)->toHaveCount(1)
        ->and($tokens->item(0)->getAttribute('value'))->toBe(session()->token());

    $buttons = $xpath->query('.//button[@type="submit"]', $form);
    expect($buttons)->toHaveCount(1)
        ->and(trim($buttons->item(0)->textContent))->toBe('Sign out');
})->with(['/', '/events', '/profile', 'event']);

it('does not expose member account controls to guests', function (string $page) {
    $url = $page === 'event'
        ? route('events.page', Event::factory()->create(['status' => EventStatus::Published]))
        : $page;

    $this->get($url)
        ->assertOk()
        ->assertDontSee('data-testid="member-account-controls"', false)
        ->assertDontSee('Sign out');
})->with(['/', '/events', '/about', 'event']);

it('preserves the session-free about page even with an already-resolved member', function () {
    $this->actingAs(User::factory()->create());
    $this->expectsDatabaseQueryCount(0);

    $this->get('/about')
        ->assertOk()
        ->assertDontSee('data-testid="member-account-controls"', false)
        ->assertDontSee('Sign out');
});

it('keeps the profile unavailable to guests', function () {
    $this->get('/profile')->assertRedirect(route('login'));
});

it('keeps logout POST-only and CSRF-protected', function () {
    // Laravel normally bypasses CSRF in tests. Exercise the real check here.
    $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $this->actingAs(User::factory()->create())
        ->withSession(['_token' => 'shared-logout-test-token']);

    $this->get(route('logout'))->assertStatus(405);
    $this->assertAuthenticated();

    $this->post(route('logout'))->assertStatus(419);
    $this->assertAuthenticated();

    $this->post(route('logout'), ['_token' => 'wrong-token'])->assertStatus(419);
    $this->assertAuthenticated();

    $this->post(route('logout'), ['_token' => 'shared-logout-test-token'])
        ->assertRedirect('/');
    $this->assertGuest();
});
