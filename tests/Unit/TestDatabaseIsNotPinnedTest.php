<?php

// The test suite supports approved agent-testdb, disposable local developer
// instances and disposable CI services. Environment-supplied hosts/credentials
// support those setups, not arbitrary reachable hosts. The forced database name
// and name/role guard are not host approval; see docs/testing-strategy.md.
//
// If phpunit.xml pins the host or the credentials, everyone whose Postgres is not
// on 127.0.0.1 with the docker-compose password gets seven red tests and no clue
// why — and CI, which does run on 127.0.0.1, would never catch the regression.
//
// `force="true"` on the two pinned names is the other half (TOG-9649). Without
// it a real environment variable wins over phpunit.xml, so a shell exporting
// DB_DATABASE — or a worker inheriting one, as in the 2026-09-29 production
// wipe — silently repoints the suite at another database.

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

it('forces the pinned test database so the environment cannot override it', function () use ($pinnedByDatabaseName) {
    $config = simplexml_load_file(base_path('phpunit.xml'));

    $unforced = [];

    foreach ($config->php->env as $env) {
        $name = (string) $env['name'];

        if (in_array($name, $pinnedByDatabaseName, true) && (string) $env['force'] !== 'true') {
            $unforced[] = $name;
        }
    }

    expect($unforced)->toBe([], 'phpunit.xml leaves '.implode(', ', $unforced).' overridable by the environment. Set force="true" (TOG-9649).');
});
