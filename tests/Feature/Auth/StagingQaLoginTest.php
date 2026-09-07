<?php

use App\Http\Controllers\Auth\StagingQaLoginController;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

const QA_MEMBER = 'qa-member';
const QA_MODERATOR = 'qa-moderator';
const QA_UNKNOWN = 'qa-unknown';
const QA_TOKEN = 'test-only-qa-token-that-is-never-a-real-secret';

beforeEach(function () {
    app()->instance('env', 'staging');
    config(['services.staging_qa_auth.token' => QA_TOKEN]);

    registerStagingQaRoute();
});

afterEach(function () {
    app()->instance('env', 'testing');
});

function registerStagingQaRoute(): void
{
    if (Route::getRoutes()->getByName('qa.login') === null) {
        Route::middleware('web')
            ->get('/auth/qa/{identity}', StagingQaLoginController::class)
            ->name('qa.login');
    }
}

it('signs in the deterministic member fixture without carrying the token into a redirect', function () {
    $response = $this->withHeader(StagingQaLoginController::HEADER, QA_TOKEN)->get('/auth/qa/'.QA_MEMBER);

    $response->assertNoContent();
    expect($response->headers->get('Location'))->toBeNull();

    $user = User::query()->sole();

    expect($user->discord_id)->toBe('900000000000001396')
        ->and($user->username)->toBe(QA_MEMBER)
        ->and($user->is_moderator)->toBeFalse();

    $this->assertAuthenticatedAs($user);
});

it('signs in the deterministic moderator fixture without carrying the token into a redirect', function () {
    $response = $this->withHeader(StagingQaLoginController::HEADER, QA_TOKEN)->get('/auth/qa/'.QA_MODERATOR);

    $response->assertNoContent();
    expect($response->headers->get('Location'))->toBeNull();

    $user = User::query()->sole();

    expect($user->discord_id)->toBe('900000000000001397')
        ->and($user->username)->toBe(QA_MODERATOR)
        ->and($user->is_moderator)->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

it('rejects missing and wrong tokens without disclosing whether the identity exists', function (?string $token) {
    $known = $token === null
        ? $this->get('/auth/qa/'.QA_MEMBER)
        : $this->withHeader(StagingQaLoginController::HEADER, $token)->get('/auth/qa/'.QA_MEMBER);
    $unknown = $token === null
        ? $this->get('/auth/qa/'.QA_UNKNOWN)
        : $this->withHeader(StagingQaLoginController::HEADER, $token)->get('/auth/qa/'.QA_UNKNOWN);

    $known->assertNotFound();
    $unknown->assertNotFound();

    expect($known->getContent())->toBe($unknown->getContent())
        ->and($known->getStatusCode())->toBe($unknown->getStatusCode());

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with([
    'missing header' => null,
    'wrong header' => 'wrong-test-token',
]);

it('fails closed when the staging token is not configured', function () {
    config(['services.staging_qa_auth.token' => '']);

    $this->withHeader(StagingQaLoginController::HEADER, QA_TOKEN)->get('/auth/qa/'.QA_MEMBER)->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('uses the normal profile and admin authorization paths after sign-in', function () {
    $this->withHeader(StagingQaLoginController::HEADER, QA_TOKEN)->get('/auth/qa/'.QA_MEMBER)->assertNoContent();

    $this->get('/profile')->assertOk();
    $this->get('/admin')->assertForbidden();

    auth()->logout();

    $this->withHeader(StagingQaLoginController::HEADER, QA_TOKEN)->get('/auth/qa/'.QA_MODERATOR)->assertNoContent();
    $this->get('/admin')->assertOk();
});

it('returns 404 outside staging even if the route was cached or manually registered', function (string $environment) {
    app()->instance('env', $environment);

    $this->withHeader(StagingQaLoginController::HEADER, QA_TOKEN)->get('/auth/qa/'.QA_MEMBER)->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with(['production', 'local']);

it('never exposes the token through logs, exceptions, redirects, or rendered responses', function () {
    Log::spy();

    foreach ([QA_MEMBER, QA_UNKNOWN] as $identity) {
        foreach ([null, 'wrong-test-token', QA_TOKEN] as $token) {
            try {
                $response = $token === null
                    ? $this->get('/auth/qa/'.$identity)
                    : $this->withHeader(StagingQaLoginController::HEADER, $token)->get('/auth/qa/'.$identity);

                expect($response->getContent())->not->toContain(QA_TOKEN)
                    ->and(json_encode($response->headers->all(), JSON_THROW_ON_ERROR))->not->toContain(QA_TOKEN);
            } catch (Throwable $exception) {
                expect((string) $exception)->not->toContain(QA_TOKEN);
            }
        }
    }

    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('notice');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('critical');
    Log::shouldNotHaveReceived('alert');
    Log::shouldNotHaveReceived('emergency');
});
