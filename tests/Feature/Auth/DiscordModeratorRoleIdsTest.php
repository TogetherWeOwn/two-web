<?php

/**
 * Who the admin panel opens for.
 *
 * `DISCORD_MODERATOR_ROLE_IDS` is the only privilege grant the site has, and it is
 * the only one that can be wrong without anything going red. A wrong guild ID locks
 * everybody out loudly — members click Sign in and it does not work. A wrong
 * moderator list does the opposite: every member still signs in, every page still
 * renders, and the admin link is simply never offered to anyone. The variable looks
 * configured. The panel is dark. Nothing logs it.
 *
 * That is not hypothetical. Five other TWO roles carry ban/kick, and all five are
 * deleted in Wave 6 of the approved server redesign. A deleted snowflake does not
 * error — it just stops appearing in the member's role list, so `isModerator()`
 * quietly returns false forever. Matching by snowflake is what makes a *rename*
 * safe; nothing makes a *deletion* safe except picking a role that survives.
 *
 * So this file pins the parsing, the way DiscordGuildIdTest pins the guild default.
 * `isModerator()` itself — the intersect, and the closed door when the list is
 * empty — is covered in DiscordLoginTest; the rendered admin link is covered in
 * tests/Browser/DiscordLoginTest.php. What was never covered is the step in
 * between: turning one line of a `.env` file into the list those two rely on.
 *
 * The value that belongs on a real box is `508654771276873729` — `SySOp` in the TWO
 * guild, decided on TOG-106. It appears below as an input fixture, not as an
 * expectation about config: the authority for who is a moderator lives in the
 * environment, never in the repository, because blanking the variable is the
 * revocation path and a default in code would take that away.
 */
const TWO_MODERATOR_ROLE_ID = '508654771276873729';

/**
 * Resolve config/services.php the way a fresh boot would, with
 * DISCORD_MODERATOR_ROLE_IDS set to $value — or absent entirely when $value is null.
 *
 * Both `$_SERVER` and `$_ENV` have to be written. phpdotenv populates both, and
 * Laravel's Env repository reads `$_SERVER` first — so setting only `$_ENV` leaves
 * the old value winning and the test quietly passes against nothing.
 *
 * @return array<int, string>
 */
function moderatorRoleIdsFor(?string $value): array
{
    $key = 'DISCORD_MODERATOR_ROLE_IDS';
    $saved = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null];

    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    } else {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }

    try {
        return (require config_path('services.php'))['discord']['moderator_role_ids'];
    } finally {
        [$env, $server] = $saved;

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

it('reads one role id as one role id', function () {
    // The shape a real environment carries: a bare snowflake, nothing else.
    expect(moderatorRoleIdsFor(TWO_MODERATOR_ROLE_ID))->toBe([TWO_MODERATOR_ROLE_ID]);
});

it('lets nobody be a moderator when the variable is blank', function () {
    // The safe default, and the revocation path: blanking the variable un-grants
    // everyone with no deploy. It only works because the list comes out *empty* —
    // `isModerator()` returns false on `[]` before it ever looks at a role.
    expect(moderatorRoleIdsFor(''))->toBe([]);
});

it('lets nobody be a moderator when the variable is not set at all', function () {
    // A box provisioned from a checklist that missed the line must fail closed too,
    // not fatal on a missing key.
    expect(moderatorRoleIdsFor(null))->toBe([]);
});

it('reads a comma separated list, and forgives the spaces someone will type', function () {
    // Two roles, entered the way a human enters them. Without the `trim` the second
    // id arrives as " 900000000000000042", which matches no Discord role ever — and
    // matches it silently.
    expect(moderatorRoleIdsFor(TWO_MODERATOR_ROLE_ID.', 900000000000000042'))
        ->toBe([TWO_MODERATOR_ROLE_ID, '900000000000000042']);
});

it('drops a trailing comma instead of counting it as a role', function () {
    // `explode` on "123," yields ["123", ""], and an empty string in the list is
    // worse than useless: it makes the list non-empty, so the fail-closed guard in
    // `isModerator()` stops firing while nothing has actually been granted.
    expect(moderatorRoleIdsFor(TWO_MODERATOR_ROLE_ID.','))->toBe([TWO_MODERATOR_ROLE_ID]);
});

it('hands isModerator a list it can compare against Discord, not a string', function () {
    // Discord returns roles as a JSON array of string snowflakes and `isModerator()`
    // does `array_intersect($roles, $moderatorRoles)`. Loose comparison against an
    // int would still match here, but the keys must be sequential — `array_values`
    // is why, and dropping it would leave gaps after a filtered entry.
    $ids = moderatorRoleIdsFor(','.TWO_MODERATOR_ROLE_ID);

    expect($ids)->toBe([TWO_MODERATOR_ROLE_ID])
        ->and($ids[0])->toBeString();
});
