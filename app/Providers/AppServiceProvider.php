<?php

namespace App\Providers;

use App\Models\Profile;
use App\Models\User;
use App\Services\Bot\InternalActionClient;
use App\Services\Paperclip\RestartCardClient;
use App\Support\Counts\CountsReader;
use App\Support\Counts\CountsSource;
use App\Support\Events\DiscordEventsReader;
use App\Support\Events\DiscordEventsSource;
use App\Support\MemberDataAccess\AccessRecorder;
use App\Support\Profiles\MemberStatsReader;
use App\Support\Profiles\MemberStatsSource;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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

        // The landing page's counts. The page depends on the interface rather
        // than on the reader, so it depends on "something that supplies counts"
        // and not on the bot's database being reachable — which is also the
        // seam the degraded state is tested through. Not shared: the 60-second
        // cache inside the reader already does the deduplication, and holding
        // one for a worker's lifetime would only keep a stale connection alive.
        $this->app->bind(CountsSource::class, CountsReader::class);

        // A profile performs two fixed reads against the bot's versioned views:
        // one member row, then the complete milestone list. The controller sees
        // only this non-throwing contract, so an unavailable bot database cannot
        // take the member-owned half of the profile down with it.
        $this->app->bind(MemberStatsSource::class, MemberStatsReader::class);

        // The calendar's Discord-native rows (TOG-5168): one cached read of
        // `web_v1.upcoming_events`, merged into the local rows by the
        // component. Bound, not shared, for the same reason as the counts
        // reader — the cache inside already deduplicates, and a singleton
        // would only keep a stale bot connection alive on a worker.
        $this->app->bind(DiscordEventsSource::class, DiscordEventsReader::class);

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

        // The board-write seam for cold-setting restart cards (TOG-3537). Bound,
        // not shared, for the same reason as the bot client: it reads config at
        // resolve time and holds no state, so a singleton would only risk a stale
        // token surviving a config change. Every value is passed in, including
        // the missing ones — the client decides that a blank value is a
        // PaperclipNotConfiguredException, which is what makes the filer fail
        // closed rather than failing at container resolution.
        $this->app->bind(RestartCardClient::class, fn (Application $app): RestartCardClient => new RestartCardClient(
            url: $this->stringConfig('services.paperclip.url'),
            token: $this->stringConfig('services.paperclip.token'),
            companyId: $this->stringConfig('services.paperclip.company_id'),
            operatorLabelId: $this->stringConfig('services.paperclip.operator_label_id'),
            parentIssueId: $this->stringConfig('services.paperclip.parent_issue_id'),
            restartAssigneeAgentId: $this->stringConfig('services.paperclip.restart_assignee_agent_id'),
            botEnvironment: $this->stringConfig('services.paperclip.bot_environment'),
            timeoutSeconds: (int) config('services.paperclip.timeout', 5),
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
        // Neither attribute is claimed to have fixed LCP. Both are kept because
        // both are correct on their own terms — defer is load-bearing for FCP,
        // and low priority is safe precisely because the script is deferred,
        // with nothing before DOMContentLoaded waiting on it.
        //
        // What DID fix LCP was removing bytes, not reordering them. The budget
        // is bandwidth-bound (ci/lighthouserc.cjs simulates Slow 4G at ~184
        // KB/s), so a resource that blocks nothing still pushes LCP out while
        // the largest element waits for its font. Two things were shipping
        // needlessly: this runtime went over the wire uncompressed because
        // Livewire serves it from a PHP route nothing in front of the app can
        // see (fixed in App\Http\Middleware\CompressStaticAssets — 162 KB ->
        // 55 KB, verified over HTTP), and the app bundle was 48 KB of axios
        // that nothing imported (fixed in resources/js/app.js). Both are
        // asserted in tests/Feature/AssetCompressionTest.php.
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
        Gate::define('access-admin', fn (User $user): bool => $user->is_moderator === true);

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

        // A failed job is the only queue outcome nobody watches by habit. A
        // stuck or dead worker is visible in `queue:check-depth` (pending grows,
        // reserved sticks), but a job that fails terminally — a bot refusal, a
        // malformed payload — just sits in `failed_jobs` while the member's
        // RSVP reads "pending" forever. The worker already owns recovery (the
        // reconcile pass re-dispatches); what is missing is the surfacing, so
        // this listener is the alert half of TOG-6948: one critical log line per
        // failed job, with the class, queue and exception message as structured
        // context, so whatever tails the log on the box sees it without having
        // to remember to query the table. `failing` fires for every driver —
        // database, sync, null — and for jobs that call `fail()` themselves as
        // well as jobs the worker gives up on, so the dead-letter path and the
        // exhausted-retries path both land here. `job` is the class via
        // resolveQueuedJobClass, not resolveName: a job with a displayName
        // (like the poison probe's marker) would otherwise log the instance
        // label where a greppable class belongs. The instance label still goes
        // out as `display`, so the alert carries both what broke and which one.
        Queue::failing(function (JobFailed $event): void {
            Log::critical('Queue job failed.', [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveQueuedJobClass(),
                'display' => $event->job->resolveName(),
                'attempts' => $event->job->attempts(),
                'exception' => get_class($event->exception),
                'message' => $event->exception->getMessage(),
            ]);
        });
    }
}
