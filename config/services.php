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

        /*
         | The TWO server. Roles are read per guild, so a blank one stops every
         | member at the door — and that outage is invisible until somebody
         | clicks the button, because nothing at deploy time knows it is missing.
         |
         | TWO has exactly one Discord server, this is not a secret, and anyone
         | can check it without credentials:
         |   GET https://discord.com/api/v10/guilds/326474832151838730/widget.json
         |     -> {"id":"326474832151838730","name":"TogetherWeOwn", ...}
         | So it is a default here and the variable only overrides it. Getting
         | this wrong now takes actively typing a wrong snowflake rather than
         | forgetting a line.
         |
         | `?:` and not `??`: phpdotenv reads `DISCORD_GUILD_ID=` as an empty
         | string rather than as absent, and every .env in this repo has that
         | blank line — with `??` the blank would win and the default would
         | never fire. tests/Feature/Auth/DiscordGuildIdTest.php pins that.
         */
        'guild_id' => env('DISCORD_GUILD_ID') ?: '326474832151838730',

        // Role IDs, comma separated. Snowflakes, never names: a renamed role must
        // not quietly hand out or take away the admin panel. Blank means nobody
        // is a moderator.
        'moderator_role_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DISCORD_MODERATOR_ROLE_IDS', '')),
        ))),

        'api_base' => env('DISCORD_API_BASE', 'https://discord.com/api/v10'),
        'timeout' => (int) env('DISCORD_TIMEOUT_SECONDS', 5),

        /*
         | The plain invite link, and the floor under one-click join (TOG-80).
         |
         | One-click routes through the bot, so it is only up while the bot is
         | up. Every way that can fail — bot down, action not switched on,
         | Discord refusing, member cancelling — ends by showing this link
         | instead. Losing one-click is a downgrade; losing the click is losing
         | the recruit.
         |
         | No default. Unlike the guild id, an invite code cannot be derived from
         | anything public and it expires if it is created with an expiry, so a
         | stale constant compiled into the repo would be worse than an empty one
         | the join page can notice and report. Create it non-expiring, with no
         | use limit.
         */
        'invite_url' => env('DISCORD_INVITE_URL'),
    ],

    /*
     | The bot's internal action endpoint (TWO-24). Private network, HMAC-signed
     | with the shared secret, explicit allowlist of actions on the bot's side.
     */
    'bot' => [
        'url' => env('BOT_ENDPOINT_URL'),
        'secret' => env('BOT_SHARED_SECRET'),
        'timeout' => (int) env('BOT_TIMEOUT_SECONDS', 5),

        /*
         | Which shared secret signed the request, sent as X-TWO-Key-Id. The bot
         | keeps a ring of them so one caller's key can be rotated without an
         | outage: add the new id there, switch this, retire the old one.
         |
         | An unknown key id and a bad signature are the same response by design,
         | so getting this wrong presents as a flat 401 with no hint. It is not a
         | secret — it names one.
         */
        'key_id' => env('BOT_KEY_ID', 'web-local'),

        /*
         | guild.add_member gets its own, shorter budget than the generic 5s: a
         | member is standing in front of the join page waiting for it.
         |
         | 2s here, and the bot spends at most 1500ms of it on Discord, so it
         | returns a typed error inside our window rather than both sides timing
         | out and this one having to guess (INTERNAL_ACTIONS.md §5). Raising
         | this without raising the bot's budget buys nothing.
         */
        'add_member_timeout' => (int) env('BOT_ADD_MEMBER_TIMEOUT_SECONDS', 2),
    ],

];
