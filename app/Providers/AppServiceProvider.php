<?php

namespace App\Providers;

use App\Models\Profile;
use App\Models\User;
use App\Services\Bot\InternalActionClient;
use App\Support\MemberDataAccess\AccessRecorder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: one recorder per request, and a queue worker
        // that never resolves it does not accumulate one member's ids into the
        // next member's row.
        $this->app->scoped(AccessRecorder::class);

        // Bound rather than shared: it reads config at resolve time and holds no
        // state between calls, so a singleton would only buy the chance of a
        // stale secret surviving a config change.
        //
        // Every value is passed in, including the missing ones. The client itself
        // decides that a blank secret is a BotNotConfiguredException — deciding it
        // here would mean an unconfigured environment failed at container
        // resolution, which is a harder failure to catch and to test than one
        // thrown from the call that needed the secret.
        $this->app->bind(InternalActionClient::class, fn (Application $app): InternalActionClient => new InternalActionClient(
            url: $this->stringConfig('services.bot.url'),
            secret: $this->stringConfig('services.bot.secret'),
            keyId: $this->stringConfig('services.bot.key_id'),
            timeoutSeconds: (int) config('services.bot.timeout', 5),
        ));
    }

    /** Config values arrive as mixed; the client wants a string or nothing. */
    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) ? $value : null;
    }

    public function boot(): void
    {
        // Livewire injects its runtime as a plain <script src> with no defer, which
        // puts 162 KB in the critical path of every Livewire page. On the budget
        // profile (mid-range phone, 4x CPU, Slow 4G) that is about 900ms of
        // transfer ahead of the paint, and it measurably breached the LCP budget
        // when /events shipped: 2616ms against 2000ms, with FCP at 2166ms.
        //
        // `defer` rather than `async`, deliberately. The runtime binds to the
        // components already in the document, so it must run after the parse has
        // finished; `async` would let it execute mid-parse against a half-built
        // DOM. `defer` also keeps execution ordered against the app bundle.
        //
        // Asserted in tests/Feature/CriticalPathTest.php so this cannot regress
        // quietly — a Livewire upgrade that changes how the tag is emitted fails
        // there in half a second, rather than as an unexplained budgets breach.
        //
        // What this did and did not fix, measured rather than assumed. `defer`
        // cleared the FCP warning: 2166ms before, under the 1800ms threshold
        // after, and the warning has not come back. It did NOT clear LCP, which
        // went 2616ms -> 2684ms. `fetchpriority="low"` was then tried on the
        // theory that the runtime was competing for bandwidth with the paint,
        // and the measurement disproved it: LCP moved to 2666ms, ~18ms, noise.
        //
        // Where the time actually goes, now that the budget job reports the
        // passing pages too: FCP on /events is 1258ms and on / it is 1276ms —
        // the same. The first paint is NOT delayed, so nothing here is blocking
        // it and no further work on this script will move LCP. The gap is
        // entirely after the paint: /events reaches LCP at 2663ms against 1655ms
        // on /, about 1400ms spent between painting something and painting the
        // largest thing. That is the remaining problem and it is not this one.
        // See TOG-53.
        //
        // Both attributes are kept because both are correct on their own terms —
        // defer is load-bearing for FCP, and low priority is safe precisely
        // because the script is deferred, with nothing before DOMContentLoaded
        // waiting on it. Neither is claimed to have fixed LCP.
        Livewire::useScriptTagAttributes(['defer' => true, 'fetchpriority' => 'low']);

        // Socialite ships no Discord driver of its own; this registers the
        // community one. Reason for the dependency: writing our own OAuth2
        // provider is about seventy lines we would then own and get subtly
        // wrong, against a package the Laravel ecosystem already leans on.
        Event::listen(SocialiteWasCalled::class, [DiscordExtendSocialite::class, 'handle']);

        // The one permission the site has. It is recomputed from the member's
        // Discord roles on every login — see DiscordLoginController — so removing
        // somebody's moderator role in Discord removes it here at their next
        // sign-in. There is no way to grant it from inside the website.
        Gate::define('access-admin', fn (User $user): bool => $user->is_moderator);

        // Reading member data through the admin panel gets recorded, and the
        // recording hangs off model hydration rather than off each screen
        // remembering to declare what it showed. See AccessRecorder for why.
        //
        // These listeners are registered for the whole application but the
        // recorder ignores everything until the panel's middleware arms it, so
        // an ordinary page costs one `if` per hydrated model and writes nothing.
        foreach ([User::class, Profile::class] as $model) {
            Event::listen(
                'eloquent.retrieved: '.$model,
                fn (User|Profile $subject) => app(AccessRecorder::class)->observe($subject),
            );
        }
    }
}
