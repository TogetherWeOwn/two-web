<?php

namespace App\Providers;

use App\Models\Profile;
use App\Models\User;
use App\Services\Bot\InternalActionClient;
use App\Support\Counts\CountsReader;
use App\Support\Counts\CountsSource;
use App\Support\MemberDataAccess\AccessRecorder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
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

        // The landing page's counts. The page depends on the interface rather
        // than on the reader, so it depends on "something that supplies counts"
        // and not on the bot's database being reachable — which is also the
        // seam the degraded state is tested through. Not shared: the 60-second
        // cache inside the reader already does the deduplication, and holding
        // one for a worker's lifetime would only keep a stale connection alive.
        $this->app->bind(CountsSource::class, CountsReader::class);

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
