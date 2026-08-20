<?php

/**
 * Which Discord server we are.
 *
 * This is the one Discord value that is not a secret and never varies: TWO has
 * exactly one server, and every environment — local, staging, production — reads
 * roles out of that same server. It was an ordinary environment variable, blank
 * by default, and blank means `guildMember()` returns `unavailable` and *nobody
 * can sign in at all*. That failure shows up in no test, no log and no deploy
 * step. It shows up when a member clicks the button.
 *
 * So the server ID has a default in config, and the variable only exists to
 * override it. A deployment can now only get this wrong by actively typing a
 * wrong snowflake, rather than by forgetting a line.
 *
 * The ID is verifiable by anyone, no credentials needed:
 *   GET https://discord.com/api/v10/guilds/326474832151838730/widget.json
 *     -> {"id":"326474832151838730","name":"TogetherWeOwn", ...}
 */
const TWO_GUILD_ID = '326474832151838730';

/**
 * Resolve config/services.php the way a fresh boot would, with DISCORD_GUILD_ID
 * set to $value — or absent entirely when $value is null.
 *
 * Both `$_SERVER` and `$_ENV` have to be written. phpdotenv populates both, and
 * Laravel's Env repository reads `$_SERVER` first — so setting only `$_ENV`
 * leaves the old value winning and the test quietly passes against nothing.
 */
function discordConfigWithGuild(?string $value): array
{
    $keys = ['DISCORD_GUILD_ID'];
    $saved = [];

    foreach ($keys as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];

        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    try {
        return (require config_path('services.php'))['discord'];
    } finally {
        foreach ($saved as $key => [$env, $server]) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            if ($env !== null) {
                $_ENV[$key] = $env;
                putenv($key.'='.$env);
            }

            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
        }
    }
}

it('knows which Discord server we are without anyone setting a variable', function () {
    // Red before the fix: this was env('DISCORD_GUILD_ID') with no default, so a
    // checkout that had not been told the server ID locked every member out.
    expect(discordConfigWithGuild(null)['guild_id'])->toBe(TWO_GUILD_ID);
});

it('treats a blank guild variable as not set, rather than as a blank server', function () {
    // phpdotenv reads `DISCORD_GUILD_ID=` as an empty string, not as absent, so a
    // `??` default never fires and the blank wins. Every .env in this repo has
    // exactly that blank line in it — which is why the default has to be `?:`.
    // This is the test that goes red if `??` creeps back in.
    expect(discordConfigWithGuild('')['guild_id'])->toBe(TWO_GUILD_ID);
});

it('still lets a real environment point at a different server', function () {
    // The override has to keep working: QA's test server is not this one, and
    // the staging cutover swaps it.
    expect(discordConfigWithGuild('111111111111111111')['guild_id'])->toBe('111111111111111111');
});
