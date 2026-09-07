<?php

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

        expect($application['router']->getRoutes()->getByName('qa.login'))->toBeNull();
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
})->with(['production', 'local']);

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

        expect($route->uri())->toBe('auth/qa/{identity}');
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
