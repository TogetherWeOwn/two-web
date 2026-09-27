<?php

/**
 * The moderator *reference* data lives in config, not in class constants.
 *
 * `DISCORD_MODERATOR_ROLE_IDS` is the grant — read from the environment with
 * deliberately no default, because blanking it is the revocation path. The two
 * values below are the reference the grant is compared *against*: the signed-off
 * SySOp ID (`services.discord.sysop_role_id`) and the five deleted ban/kick
 * roles (`services.discord.retired_moderator_role_ids`). They used to be
 * `SYSOP`/`DOOMED` constants on `CheckDiscordModerators`, with a second copy of
 * the SySOp ID as `MODERATOR_ROLE_ID` on `StagingQaLoginController`; a reviewed
 * box could not point at a different server without a code change, and the two
 * copies could drift.
 *
 * What is pinned here is the wiring, not the values' fitness: the default is
 * the TOG-106 value, a blank override line falls back to it, an explicit line
 * re-points it, the retired set carries the five audit IDs as string IDs, and
 * both consumers — the check command and the QA fixture — follow the config
 * rather than a constant. ID-match semantics (including deleted-role
 * dark-fail) are pinned in CheckDiscordModeratorsTest; parsing in
 * DiscordModeratorRoleIdsTest.
 */

use App\Http\Controllers\Auth\StagingQaLoginController;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

const REFERENCE_SYSOP_ID = '508654771276873729';

const RETIRED_FIVE_IDS = [
    '1078757544169848933',
    '1087192823767515219',
    '1078757266469175386',
    '1078757184021733426',
    '1078756990710452365',
];

const CONFIG_QA_TOKEN = 'test-only-qa-token-that-is-never-a-real-secret';

/**
 * Resolve config/services.php the way a fresh boot would, with the given
 * variables set — or absent entirely when the value is null.
 *
 * Both `$_SERVER` and `$_ENV` have to be written. phpdotenv populates both, and
 * Laravel's Env repository reads `$_SERVER` first — so setting only `$_ENV`
 * leaves the old value winning and the test quietly passes against nothing.
 *
 * @param  array<string, string|null>  $overrides
 * @return array<string, mixed>
 */
function discordReferenceConfigFor(array $overrides): array
{
    $saved = [];

    foreach ($overrides as $key => $value) {
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

function registerReferenceQaRoute(): void
{
    if (Route::getRoutes()->getByName('qa.login') === null) {
        Route::middleware('web')
            ->get('/auth/qa/{identity}', StagingQaLoginController::class)
            ->name('qa.login');
    }
}

it('defaults the sysop reference to the value TOG-106 signed off', function () {
    $discord = discordReferenceConfigFor(['DISCORD_SYSOP_ROLE_ID' => null]);

    expect($discord['sysop_role_id'])->toBe(REFERENCE_SYSOP_ID);
});

it('treats a blank sysop line as unset, so it cannot beat the signed-off value', function () {
    // `?:`, not `??`: an empty `DISCORD_SYSOP_ROLE_ID=` line in an environment
    // panel must fall back rather than configure an ID that matches nothing.
    $discord = discordReferenceConfigFor(['DISCORD_SYSOP_ROLE_ID' => '']);

    expect($discord['sysop_role_id'])->toBe(REFERENCE_SYSOP_ID);
});

it('lets an explicit sysop line point a box at a different server', function () {
    $discord = discordReferenceConfigFor(['DISCORD_SYSOP_ROLE_ID' => '900000000000000042']);

    expect($discord['sysop_role_id'])->toBe('900000000000000042');
});

it('carries the five deleted ban/kick roles as string-keyed reference data', function () {
    $discord = discordReferenceConfigFor([]);

    $retired = [];
    foreach ((array) $discord['retired_moderator_role_ids'] as $id => $label) {
        // PHP stores digit-only array keys as ints; the command casts back to
        // string before comparing, so the assertion does the same.
        $retired[(string) $id] = $label;
    }

    expect(array_keys($retired))->toEqualCanonicalizing(RETIRED_FIVE_IDS)
        ->and($retired['1078757544169848933'])->toContain('Officer');
});

it('passes a grant that exactly matches the configured sysop', function () {
    config([
        'services.discord.sysop_role_id' => '900000000000000042',
        'services.discord.moderator_role_ids' => ['900000000000000042'],
    ]);

    $code = Artisan::call('discord:check-moderators', ['--require-configured' => true]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('0 fail');
});

it('fails a grant that omits the configured sysop, even if it holds the old constant', function () {
    // Pre-move this input passed: the constant and the grant agreed. Now the
    // comparison follows config, so a grant holding only the default ID fails
    // on a box re-pointed elsewhere — which is the behaviour that makes the
    // override meaningful rather than decorative.
    config([
        'services.discord.sysop_role_id' => '900000000000000042',
        'services.discord.moderator_role_ids' => [REFERENCE_SYSOP_ID],
    ]);

    $code = Artisan::call('discord:check-moderators', ['--require-configured' => true]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('is-sysop')
        ->and(Artisan::output())->toContain('900000000000000042');
});

it('flags whatever the configured retired list names, matched by ID', function () {
    // The retired set is code-owned, so no environment override exists — but the
    // command must still read it from config rather than a constant, which is
    // what swapping in a synthetic entry proves.
    config([
        'services.discord.retired_moderator_role_ids' => ['900000000000000042' => 'Synthetic retired role'],
        'services.discord.moderator_role_ids' => [REFERENCE_SYSOP_ID, '900000000000000042'],
    ]);

    $code = Artisan::call('discord:check-moderators', ['--require-configured' => true]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('no-doomed-roles')
        ->and(Artisan::output())->toContain('Synthetic retired role');
});

describe('staging QA fixture', function () {
    beforeEach(function () {
        app()->instance('env', 'staging');
        config(['services.staging_qa_auth.token' => CONFIG_QA_TOKEN]);

        registerReferenceQaRoute();
    });

    afterEach(function () {
        app()->instance('env', 'testing');
    });

    it('signs the qa-moderator fixture in against the configured sysop', function () {
        // The fixture holds `sysop_role_id`, so re-pointing the reference moves
        // the fixture with it. Against a hardcoded constant this login would
        // come back a non-moderator.
        config(['services.discord.sysop_role_id' => '900000000000000077']);

        $response = $this->withHeader(StagingQaLoginController::HEADER, CONFIG_QA_TOKEN)->get('/auth/qa/qa-moderator');

        $response->assertNoContent();

        $user = User::query()->sole();

        expect($user->is_moderator)->toBeTrue();

        $this->get('/admin')->assertOk();
    });
});
