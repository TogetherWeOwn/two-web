<?php

// The test suite has to be able to run against whatever Postgres you can reach:
// docker-compose on your laptop, a service container in CI, a shared server in a
// sandbox with no Docker. The only thing tests must force is the database *name*,
// so a run can never wipe your development data.
//
// If phpunit.xml pins the host or the credentials, everyone whose Postgres is not
// on 127.0.0.1 with the docker-compose password gets seven red tests and no clue
// why — and CI, which does run on 127.0.0.1, would never catch the regression.

$pinnedByDatabaseName = ['DB_CONNECTION', 'DB_DATABASE'];

it('lets the environment choose which Postgres the suite talks to', function () use ($pinnedByDatabaseName) {
    $config = simplexml_load_file(base_path('phpunit.xml'));

    $pinned = [];

    foreach ($config->php->env as $env) {
        $name = (string) $env['name'];

        if (str_starts_with($name, 'DB_') && ! in_array($name, $pinnedByDatabaseName, true)) {
            $pinned[] = $name;
        }
    }

    expect($pinned)->toBe([], 'phpunit.xml pins '.implode(', ', $pinned).'. Let .env supply these.');
});
