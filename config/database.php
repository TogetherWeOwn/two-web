<?php

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Postgres, and only Postgres. Laravel ships sqlite, mysql, mariadb and
    | sqlsrv connections too; they are deleted here on purpose. We run Postgres
    | in production and in the test suite, and a second engine sitting in the
    | config is only ever an invitation to accidentally develop against it.
    |
    */

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'two_web'),
            'username' => env('DB_USERNAME', 'two_web'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        /*
         | The Discord bot's database, read through the versioned views it
         | publishes for us (TWO-23). Separate connection, separate role, and
         | that role has SELECT on those views and nothing else — so a bug on
         | our side cannot write to the bot's data even if it tries.
         |
         | Blank credentials are expected before the views exist. Anything
         | reading this connection must survive it being unavailable: the
         | landing page falls back to a cached count, it does not white-screen.
         */
        'bot' => [
            'driver' => 'pgsql',
            'host' => env('BOT_DB_HOST', '127.0.0.1'),
            'port' => env('BOT_DB_PORT', '5432'),
            'database' => env('BOT_DB_DATABASE', 'two_bot'),
            'username' => env('BOT_DB_USERNAME', ''),
            'password' => env('BOT_DB_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    // No Redis. Cache, sessions and the queue all run on Postgres. If we ever
    // outgrow that, it is a conversation with the CEO, not a config change.

];
