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

            /*
             | Pinned, and deliberately not from the environment. Laravel hands
             | Postgres times as plain 'Y-m-d H:i:s' strings with no offset, and
             | Postgres reads those into a timestamptz column using the *session*
             | time zone — which otherwise comes from whatever the server happened to
             | be initdb'd with. That would make the stored instant a property of the
             | machine: events an hour out on one host and not another, and only for
             | half the year. UTC in, UTC out. The zone an event is rendered in is
             | its own column on the row, which is a different question.
             */
            // PostgreSQL accepts the canonical IANA spelling, not PHP's UTC alias.
            'timezone' => 'Etc/UTC',
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

            /*
             | How long the landing page is willing to wait for the bot's
             | database before giving up and rendering the degraded counts.
             |
             | It has to be here because libpq's default is **30 seconds** and
             | the homepage has a 2.0s LCP budget: a bot host that accepts no
             | connections — powered off, firewalled, mid-deploy — would hold
             | the page for half a minute and breach the budget by 15x. Failing
             | in two seconds and rendering the designed empty state is the
             | behaviour the counts contract asks for.
             |
             | PDO::ATTR_TIMEOUT, and *not* `connect_timeout` in the DSN, which
             | is the obvious spelling and is silently ignored: measured on PHP
             | 8.3.29, a DSN carrying connect_timeout=3 still took 30.03s to
             | fail against a black-holed host, while ATTR_TIMEOUT=3 failed in
             | 3.00s. Laravel merges this array into the PDO options
             | (Connector::getOptions), so it reaches the driver.
             |
             | Only on this connection. The app's own database is not optional:
             | if `pgsql` is unreachable there is no page to degrade to, and a
             | short timeout there would turn a slow query into a broken site.
             */
            'options' => [
                PDO::ATTR_TIMEOUT => (int) env('BOT_DB_TIMEOUT', 2),
            ],
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    // No Redis. Cache, sessions and the queue all run on Postgres. If we ever
    // outgrow that, it is a conversation with the CEO, not a config change.

];
