<?php

return [
    'dusk_test_seams' => (bool) env('DUSK_TEST_SEAMS', false),

    // An inner gate for the staging-only QA identities. Cloudflare Access stays in
    // front; the value is injected only into staging and never belongs in this repo.
    'staging_qa_auth' => [
        'token' => env('TWO_WEB_STAGING_QA_AUTH_TOKEN'),
    ],

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

        /*
         | The signed-off moderator and the roles that must never be granted.
         |
         | `sysop_role_id` is `SySOp` in the TWO guild — one holder,
         | administrator class, signed off on TOG-106 as the entire website
         | staff list. Matched by ID and never by name: SySOp is `KEEP (renamed
         | Owner)` in Wave 6 of the approved server-redesign, and the rename
         | preserves the snowflake, so a name match would break on a cosmetic
         | rename while an ID match rides straight through it.
         |
         | This is reference data, not a grant: nothing here grants the panel by
         | itself. The grant is `moderator_role_ids` above, read from the
         | environment with deliberately no default — blanking the variable is
         | the revocation path, and a default in code would take that away.
         | `discord:check-moderators` compares the grant against this value.
         |
         | `?:` and not `??`: a blank `DISCORD_SYSOP_ROLE_ID=` line must fall
         | back to the signed-off value rather than beating it, the same reason
         | `guild_id` above uses `?:`. Set it only to point a box at a different
         | server, e.g. QA's own.
         */
        'sysop_role_id' => env('DISCORD_SYSOP_ROLE_ID') ?: '508654771276873729',

        /*
         | The five other roles that carry ban or kick, each with the holder
         | count recorded by the 2026-08-19 audit.
         |
         | These are here because they are the *plausible* wrong answer. "Discord
         | already trusts this role to ban or kick" is a reasonable-sounding way
         | to derive a moderator list, it was in fact approved at
         | 2026-08-19T20:40Z, and the CEO narrowed it to SySOp alone three
         | minutes later (two-bot/audit/IDENTIFIERS.md:56-62). Somebody widening
         | the grant back out is not a hypothetical — it is a decision that was
         | already made once and then reversed, and the reversal is only written
         | down in another repository.
         |
         | Adding any of them is worse than merely wrong. All five are deleted:
         | Officer, Game Master and Staff in Wave 6 of the server-redesign
         | (two-bot/scripts/wave0-export.ts:74-77), Captain and Lieutenant by
         | role-consolidation (two-bot/scripts/role-consolidation.ts:81-84). A
         | deleted snowflake matches nobody, forever, without erroring — so a
         | list containing them dark-fails exactly like a blank one while
         | looking configured. `discord:check-moderators` fails any grant
         | containing them.
         |
         | Code-owned reference data with no environment override: the set only
         | changes with a reviewed server redesign. Keys are snowflake strings
         | (PHP stores digit-only keys as ints; the command compares string
         | forms, exactly as the constants it replaces did).
         */
        'retired_moderator_role_ids' => [
            '1078757544169848933' => 'Officer (3 holders, DELETE in wave 6)',
            '1087192823767515219' => 'Staff (6 holders, DELETE in wave 6)',
            '1078757266469175386' => 'Game Master (1 holder, DELETE in wave 6)',
            '1078757184021733426' => 'Captain (0 holders, DELETE in role-consolidation)',
            '1078756990710452365' => 'Lieutenant (0 holders, DELETE in role-consolidation)',
        ],

        'api_base' => env('DISCORD_API_BASE', 'https://discord.com/api/v10'),
        'timeout' => (int) env('DISCORD_TIMEOUT_SECONDS', 5),
        'test_provider_url' => env('DUSK_DISCORD_PROVIDER_URL'),
    ],

    /*
     | The bot's internal action endpoint (TWO-24). Private network, HMAC-signed
     | with the shared secret, explicit allowlist of actions on the bot's side.
     */
    'bot' => [
        'url' => env('BOT_ENDPOINT_URL'),
        'secret' => env('BOT_SHARED_SECRET'),

        /*
         | Which of the bot's shared secrets we are signing with. The bot holds
         | `TWO_INTERNAL_KEYS` as `key-id:secret` pairs, so rotating our secret is:
         | add a second pair there, point this at it, retire the first. That is the
         | only reason this is a separate value from the secret itself.
         |
         | A wrong key id and a wrong signature produce the same `unauthorized`
         | from the bot, byte for byte and on purpose, so there is nothing in the
         | failure to tell them apart. App\Services\Bot\InternalActionClient
         | refuses to send at all rather than sign with a blank one.
         */
        'key_id' => env('BOT_KEY_ID', 'web-prod'),

        'timeout' => (int) env('BOT_TIMEOUT_SECONDS', 5),
    ],

    /*
     | The Paperclip control-plane, for filing an operator restart card when a
     | cold bot setting changes (TOG-3537). See docs/cold-setting-restart-cards.md
     | for the decision record and App\Services\Paperclip\RestartCardClient for
     | the mechanism.
     |
     | Server-only, and the token can write to the board — so it follows every
     | rule BOT_SHARED_SECRET above does: never committed, never rendered, never
     | sent to the browser, never logged. Any missing value makes the client fail
     | closed (PaperclipNotConfiguredException), which is what keeps cold settings
     | read-only until the token is provisioned (the operator card, TOG-3573).
     */
    'paperclip' => [
        'url' => env('PAPERCLIP_API_URL'),
        'token' => env('PAPERCLIP_API_TOKEN'),
        'company_id' => env('PAPERCLIP_COMPANY_ID'),

        // The API takes labelIds, not names, so this is the UUID of the `operator`
        // label, and the owner/operator user the card is assigned to. Both are
        // pinned by the provisioning card (TOG-3573).
        'operator_label_id' => env('PAPERCLIP_OPERATOR_LABEL_ID'),
        'operator_assignee_user_id' => env('PAPERCLIP_OPERATOR_ASSIGNEE_USER_ID'),

        'timeout' => (int) env('PAPERCLIP_API_TIMEOUT_SECONDS', 5),
    ],

];
