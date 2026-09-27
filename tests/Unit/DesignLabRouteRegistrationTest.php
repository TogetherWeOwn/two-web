<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

it('does not register the design-lab routes in production', function () {
    $originalEnvironment = $_ENV['APP_ENV'] ?? null;
    $originalServerEnvironment = $_SERVER['APP_ENV'] ?? null;

    $_ENV['APP_ENV'] = 'production';
    $_SERVER['APP_ENV'] = 'production';

    try {
        /** @var Application $application */
        $application = require base_path('bootstrap/app.php');
        $application->make(Kernel::class)->bootstrap();

        expect($application['router']->getRoutes()->getByName('design-lab.hallmark'))->toBeNull()
            ->and($application['router']->getRoutes()->getByName('design-lab.taste'))->toBeNull();
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

it('registers the design-lab routes outside production', function (string $environment) {
    $originalEnvironment = $_ENV['APP_ENV'] ?? null;
    $originalServerEnvironment = $_SERVER['APP_ENV'] ?? null;

    $_ENV['APP_ENV'] = $environment;
    $_SERVER['APP_ENV'] = $environment;

    try {
        /** @var Application $application */
        $application = require base_path('bootstrap/app.php');
        $application->make(Kernel::class)->bootstrap();

        $hallmark = $application['router']->getRoutes()->getByName('design-lab.hallmark');
        $taste = $application['router']->getRoutes()->getByName('design-lab.taste');

        expect($hallmark)->not->toBeNull()
            ->and($taste)->not->toBeNull();

        if ($hallmark === null || $taste === null) {
            return;
        }

        expect($hallmark->uri())->toBe('design-lab/hallmark')
            ->and($taste->uri())->toBe('design-lab/taste');
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
})->with(['local', 'staging', 'testing']);
