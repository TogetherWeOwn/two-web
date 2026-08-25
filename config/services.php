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

        /*
         | Where `/discord` sends people (TOG-77). It is the only web-to-Discord
         | conversion path TWO has, so it is configuration rather than a lookup:
         | resolving the invite at request time would make the funnel depend on
         | the database or the bot, and both of those are allowed to be down.
         |
         | The default is the `WEB-HOMEPAGE` code from TOG-96, created never
         | expiring and unlimited use for exactly this. Web arrivals attribute to
         | it, so replacing it with a different code silently reattributes the
         | website's joins — rotate it deliberately, not casually.
         |
         | `?:` for the same reason as `guild_id` directly above: a blank
         | `DISCORD_INVITE_URL=` line would beat a `??` default.
         |
         | An invite code is not a secret. It is a public join link that grants
         | membership of a server anyone may ask to join, which is why it is
         | committed rather than held in the secret store — a funnel that only
         | works when a secret is present is a funnel that breaks on a fresh
         | deploy. App\Http\Controllers\DiscordInviteController holds the same
         | string as its last-resort constant and validates whatever it reads
         | here before redirecting anyone to it.
         */
        'invite_url' => env('DISCORD_INVITE_URL') ?: 'https://discord.gg/4GwEDNRTtx',

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
     |
     | The caller is App\Services\Bot\InternalActionClient, queued through
     | App\Jobs\CallInternalAction (TOG-470). Wire format is two-bot
     | docs/INTERNAL_ACTIONS.md v0.3.
     */
    'bot' => [
        /*
         | The base address, not the full endpoint. The client appends
         | `/internal/actions` from the same constant it signs, so the path in
         | the canonical string and the path in the request line cannot drift
         | apart. A URL that already ends in that path is accepted unchanged,
         | because the bot's docs and its acceptance harness both quote the full
         | endpoint and somebody will paste that in here.
         */
        'url' => env('BOT_ENDPOINT_URL'),

        /*
         | Which shared secret signed the call — `X-TWO-Key-Id`. It exists so one
         | caller's key can be rotated without downtime, so it varies per
         | environment: `web-staging`, `web-prod`.
         |
         | Deliberately without a default, even though a key id is not a secret
         | and a default would make a fresh checkout appear to work. The bot
         | answers an unknown key id and a bad signature identically, on purpose
         | — so a plausible default would turn a missing line in .env into an
         | unexplained 401 on staging. With no default the client refuses to send
         | and names the missing key instead.
         */
        'key_id' => env('BOT_KEY_ID'),

        'secret' => env('BOT_SHARED_SECRET'),
        'timeout' => (int) env('BOT_TIMEOUT_SECONDS', 5),
    ],

];
