<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Mint a signed-in moderator session and print its cookie, so the budgets job can
 * measure a page behind auth.
 *
 * ci/pages.cjs carried a standing note that authenticated pages "cannot be
 * measured until the Dusk OAuth stub can hand this script a session cookie". That
 * left /admin — the entire moderator panel — with no accessibility check and no
 * performance budget, which is what the review of PR #218 caught. This command is
 * the missing piece, and it is a command rather than a step in a shell script
 * because getting the cookie right is fiddly and exactly-once work: the value has
 * to be the encrypted, prefixed form EncryptCookies expects, or the browser is
 * silently treated as a guest and the job measures the Discord redirect instead
 * of the panel.
 *
 * SAFETY: refuses to run outside local/testing/CI. It creates a moderator and
 * hands out a valid session — that is a back door anywhere it is not a throwaway
 * database, so the guard is a hard exit and not a warning.
 */
class IssueCiSessionCookie extends Command
{
    protected $signature = 'ci:session-cookie
                            {--moderator : Give the session a user who can reach /admin}';

    protected $description = 'Print a session cookie for a CI browser to measure authenticated pages';

    public function handle(): int
    {
        // `CI` is set by GitHub Actions, and read through config/ci.php because the
        // budgets job caches the config before this runs. The environment check is
        // the real gate; CI is allowed through because that job runs with
        // APP_ENV=production against a scratch Postgres service container.
        if (! app()->environment(['local', 'testing']) && ! config('ci.enabled')) {
            $this->error('ci:session-cookie refuses to run outside local, testing or CI.');

            return self::FAILURE;
        }

        // Built by hand rather than with UserFactory, which is not a style choice:
        // the budgets job installs --no-dev to measure what production serves, and
        // fakerphp/faker is a dev dependency. A factory here loads fine in every
        // local run and dies in the only job that actually calls this command.
        $user = User::create([
            'discord_id' => (string) random_int(100000000000000000, 999999999999999999),
            'username' => 'ci-'.Str::lower(Str::random(12)),
            'display_name' => 'CI Budget Probe',
            'is_moderator' => $this->option('moderator'),
            'discord_synced_at' => now(),
        ]);

        // Build a real session record through the configured driver rather than
        // faking a row, so this keeps working if the driver changes. And log in
        // through the guard rather than writing the login key by hand: the key is
        // a hash of the guard class name, and a stale hand-rolled copy of it would
        // produce a cookie the browser accepts and the app reads as a guest.
        $session = Session::driver();
        $session->setId(Str::random(40));
        $session->start();
        $session->put('_token', Str::random(40));

        Auth::guard('web')->login($user);

        $session->save();

        $name = config('session.cookie');

        // EncryptCookies wraps every cookie value in a prefix bound to the cookie
        // name and the app key, then encrypts without serialisation. Reproducing
        // that here is the whole reason this is a command.
        $value = Crypt::encrypt(
            CookieValuePrefix::create($name, Crypt::getKey()).$session->getId(),
            false
        );

        $this->line("{$name}={$value}");

        return self::SUCCESS;
    }
}
