<?php

use App\Http\Controllers\Auth\StagingQaLoginController;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

it('does not register the staging QA route outside staging', function (string $environment) {
    $originalEnvironment = $_ENV['APP_ENV'] ?? null;
    $originalServerEnvironment = $_SERVER['APP_ENV'] ?? null;

    $_ENV['APP_ENV'] = $environment;
    $_SERVER['APP_ENV'] = $environment;

    try {
        /** @var Application $application */
        $application = require base_path('bootstrap/app.php');
        $application->make(Kernel::class)->bootstrap();

        $routes = $application['router']->getRoutes();

        expect($routes->getByName('qa.login'))->toBeNull();

        // Name lookups alone would miss a renamed duplicate, so also prove no
        // URI under auth/qa exists at all in this environment.
        $qaUris = collect($routes->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_starts_with($uri, 'auth/qa'))
            ->values()
            ->all();

        expect($qaUris)->toBeEmpty();
    } finally {
        if ($originalEnvironment === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $originalEnvironment;
        }

        if ($originalServerEnvironment === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $originalServerEnvironment;
        }
    }
})->with(['production', 'local', 'testing']);

it('registers the staging QA route only in staging', function () {
    $originalEnvironment = $_ENV['APP_ENV'] ?? null;
    $originalServerEnvironment = $_SERVER['APP_ENV'] ?? null;

    $_ENV['APP_ENV'] = 'staging';
    $_SERVER['APP_ENV'] = 'staging';

    try {
        /** @var Application $application */
        $application = require base_path('bootstrap/app.php');
        $application->make(Kernel::class)->bootstrap();

        $route = $application['router']->getRoutes()->getByName('qa.login');

        expect($route)->not->toBeNull();

        if ($route === null) {
            return;
        }

        expect($route->uri())->toBe('auth/qa/{identity}')
            ->and($route->getActionName())->toBe(StagingQaLoginController::class)
            ->and($route->methods())->toContain('GET')
            ->and($route->methods())->not->toContain('POST')
            ->and($route->gatherMiddleware())->toContain('throttle:10,1');
    } finally {
        if ($originalEnvironment === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $originalEnvironment;
        }

        if ($originalServerEnvironment === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $originalServerEnvironment;
        }
    }
});
