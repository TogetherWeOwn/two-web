<?php

/**
 * `discord:check-moderators` is the deploy-time check that the moderator grant on
 * a running box is the one TOG-106 signed off (TOG-427).
 *
 * It is a check, so the only thing worth testing is that it can FAIL. A check
 * that passes on every input is worse than no check, because the green tick is
 * read as verification. Every case below therefore pins a specific wrong .env
 * value to a specific non-zero exit — and the wrong values are the ones somebody
 * would plausibly type, not arbitrary garbage.
 */

use Illuminate\Support\Facades\Artisan;

const SYSOP_ID = '508654771276873729';

/** Officer — ban/kick, 3 holders, deleted in wave 6. The plausible wrong answer. */
const OFFICER_ID = '1078757544169848933';

/**
 * Run the command with `moderator_role_ids` resolved from $value exactly as
 * config/services.php would resolve it from the environment, and hand back the
 * exit code with the rendered output.
 *
 * It goes through config/services.php rather than setting the array directly so
 * that the parsing and the checking are pinned to the same string a person types
 * into a Forge panel. Setting the array by hand would test the command against a
 * value the environment can never actually produce.
 *
 * @return array{code: int, output: string}
 */
function runCheck(?string $value, array $options = []): array
{
    $key = 'DISCORD_MODERATOR_ROLE_IDS';
    $original = $_ENV[$key] ?? null;

    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    } else {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    try {
        $resolved = (require config_path('services.php'))['discord']['moderator_role_ids'];
    } finally {
        if ($original === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $original;
            putenv("{$key}={$original}");
        }
    }

    config(['services.discord.moderator_role_ids' => $resolved]);

    $code = Artisan::call('discord:check-moderators', $options);

    return ['code' => $code, 'output' => Artisan::output()];
}

it('passes on the exact value TOG-106 signed off', function () {
    $result = runCheck(SYSOP_ID, ['--require-configured' => true]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('0 fail')
        ->and($result['output'])->toContain(SYSOP_ID);
});

it('fails when a deleted ban/kick role is in the list', function () {
    // The exact widening that was approved on 2026-08-19 and reversed three
    // minutes later. It looks configured and grants nothing once wave 6 runs.
    $result = runCheck(SYSOP_ID.','.OFFICER_ID, ['--require-configured' => true]);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('no-doomed-roles')
        ->and($result['output'])->toContain('Officer');
});

it('fails on a blank list when the grant is required', function () {
    // The silent failure this whole command exists for: the site is up, login
    // works, and the admin link is offered to nobody with no error anywhere.
    $result = runCheck('', ['--require-configured' => true]);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('configured')
        ->and($result['output'])->toContain('empty');
});

it('accepts a blank list when the grant is not required, because blank is the revocation path', function () {
    // Same input as the case above, opposite verdict. Blanking the variable is
    // the documented way to un-grant everyone with no deploy, so it must not be
    // an error in itself — only in an environment that claims to have the grant.
    $result = runCheck('', []);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('revocation path');
});

it('fails when a role name was typed where a role ID belongs', function () {
    // The natural mistake: SySOp is what the role is called everywhere a human
    // reads it. Matching is by exact string, so this grants nobody while looking
    // completely plausible in an environment panel.
    //
    // Asserted on the `shape` row specifically, via --json, rather than on the
    // word "shape" appearing in the output. That weaker assertion passed even
    // with the shape check disabled entirely, because this input also trips
    // `is-sysop` and every row name is printed on every run — the test went
    // green for a reason that had nothing to do with what it claimed to check.
    $result = runCheck('SySOp', ['--require-configured' => true, '--json' => true]);

    $decoded = json_decode($result['output'], true);

    expect($result['code'])->toBe(1)
        ->and(collect($decoded['results'])->firstWhere('name', 'shape')['status'])->toBe('FAIL');
});

it('accepts a quoted or padded value, because the environment layer already strips both', function () {
    // Measured, not assumed: phpdotenv strips a matched pair of quotes and
    // config/services.php trims each entry, so both of these resolve to the bare
    // snowflake and work. Pinned so nobody "fixes" the shape check into
    // rejecting a value that is in fact fine.
    foreach (['"'.SYSOP_ID.'"', ' '.SYSOP_ID.' '] as $value) {
        $result = runCheck($value, ['--require-configured' => true]);

        expect($result['code'])->toBe(0)
            ->and($result['output'])->toContain('0 fail');
    }
});

it('fails when SySOp is missing entirely, even if the list is otherwise well-formed', function () {
    // A well-formed snowflake that is simply the wrong one — a transposed digit,
    // or a role ID copied from the wrong guild. Shape cannot catch this.
    $result = runCheck('900000000000000042', ['--require-configured' => true]);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('is-sysop');
});

it('surfaces an unapproved widening without failing the deploy', function () {
    // SySOp plus a live, non-doomed role. Not broken — the panel works — but it
    // grants access nobody signed off, so it is UNKNOWN rather than FAIL: worth
    // a human's attention, not worth blocking a release over.
    $result = runCheck(SYSOP_ID.',1112759027554844763', ['--require-configured' => true]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('1 unknown')
        ->and($result['output'])->toContain('needs sign-off');
});

it('reports the fail-closed guarantee that every other check depends on', function () {
    $result = runCheck(SYSOP_ID, ['--require-configured' => true]);

    expect($result['output'])->toContain('fails-closed')
        ->and($result['output'])->toContain('revokes cleanly');
});

it('always states what it cannot check, so a pass is never read as full verification', function () {
    $result = runCheck(SYSOP_ID, ['--require-configured' => true]);

    expect($result['output'])->toContain('Not checkable from here')
        ->and($result['output'])->toContain('TOG-13');
});

it('emits machine-readable findings for a deploy pipeline', function () {
    $result = runCheck(SYSOP_ID.','.OFFICER_ID, ['--require-configured' => true, '--json' => true]);

    $decoded = json_decode($result['output'], true);

    expect($decoded)->toBeArray()
        ->and($decoded['ok'])->toBeFalse()
        ->and($decoded['failures'])->toBe(1)
        ->and(collect($decoded['results'])->firstWhere('name', 'no-doomed-roles')['status'])
        ->toBe('FAIL');
});
