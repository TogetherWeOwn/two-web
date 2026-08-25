<?php

namespace App\Providers;

use App\Models\Profile;
use App\Models\User;
use App\Support\MemberDataAccess\AccessRecorder;
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
