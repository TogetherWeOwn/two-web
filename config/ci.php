<?php

return [

    /*
     | Are we running inside a continuous integration job?
     |
     | GitHub Actions sets CI=true for every step. The only thing that reads this
     | is `artisan ci:session-cookie`, which mints a signed-in moderator and so
     | refuses to run anywhere that is not a throwaway database.
     |
     | This is a config entry rather than an `env()` call at the point of use for
     | a reason that bit once already: the budgets job runs `config:cache` while
     | preparing a production-shaped app, and `env()` returns null once the config
     | is cached. Read through here and the value is baked in at cache time, when
     | CI is still visible.
     */
    'enabled' => filter_var(env('CI', false), FILTER_VALIDATE_BOOL),

];
