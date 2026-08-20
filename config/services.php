<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
     | The Discord OAuth application members log in with (TWO-27).
     |
     | It is deliberately the *same* application as the bot, and that must not be
     | "tidied up" into two. Discord only lets the bot add somebody to the server
     | when the bot token belongs to the application that issued that member's
     | access token. Split them and one-click join silently turns back into "here
     | is an invite link, go and click it yourself" — which is the join we are
     | trying to stop losing. Confirmed on TWO-78.
     |
     | Same application, different credentials: these two are the only Discord
     | credentials this codebase ever holds. The bot token lives in the bot
     | process and nowhere else — do not add it here.
     */
    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        /*
         | Deliberately a path and not a full URL. Socialite resolves a leading
         | slash through the URL generator, so APP_URL is the only thing that
         | varies between environments — and APP_URL has to be right anyway.
         |
         | This used to be its own environment variable, and that was a way to
         | ship a staging box still telling Discord "localhost". Discord matches
         | this string character for character, and a mismatch stops the member at
         | Discord's own error screen where we cannot even apologise, so the fewer
         | hands on it the better. tests/Feature/Auth/DiscordRedirectUriTest.php
         | pins what each environment actually sends against the registered list.
         */
        'redirect' => '/auth/discord/callback',

        // The TWO server. Roles are read per guild, so with this blank nobody can
        // sign in — which is the safe direction to fail.
        'guild_id' => env('DISCORD_GUILD_ID'),

        // Role IDs, comma separated. Snowflakes, never names: a renamed role must
        // not quietly hand out or take away the admin panel. Blank means nobody
        // is a moderator.
        'moderator_role_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DISCORD_MODERATOR_ROLE_IDS', '')),
        ))),

        'api_base' => env('DISCORD_API_BASE', 'https://discord.com/api/v10'),
        'timeout' => (int) env('DISCORD_TIMEOUT_SECONDS', 5),
    ],

    /*
     | The bot's internal action endpoint (TWO-24). Private network, HMAC-signed
     | with the shared secret, explicit allowlist of actions on the bot's side.
     */
    'bot' => [
        'url' => env('BOT_ENDPOINT_URL'),
        'secret' => env('BOT_SHARED_SECRET'),
        'timeout' => (int) env('BOT_TIMEOUT_SECONDS', 5),
    ],

];
