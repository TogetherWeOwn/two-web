{{--
    Branded 503 (TOG-5626). Laravel renders this view automatically during
    maintenance mode (`artisan down`) and for ServiceUnavailable exceptions.

    The CTA points at the Discord invite URL directly, not at route('join'):
    during maintenance /join answers 503 too, so sending a member there is a
    dead end. The invite is config/env only (services.discord.invite_url), so
    this page works when the database is down — that is the funnel floor from
    routes/funnel.php, surfaced as a page.
--}}
<x-layouts.app title="{{ __('errors.unavailable_title') }} — {{ config('app.name') }}" robots="noindex, nofollow">
    <section class="bg-violet">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <p class="text-sm font-bold uppercase tracking-widest text-ink-muted" aria-hidden="true">503</p>
            <h1 class="u-display mt-2 text-3xl text-ink md:text-5xl">{{ __('errors.unavailable_title') }}</h1>
            <p class="mt-3 max-w-prose text-lg text-ink-muted">{{ __('errors.unavailable_body') }}</p>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ config('services.discord.invite_url') }}"
                   data-testid="discord-join"
                   rel="noopener"
                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-brand px-6
                          font-semibold text-on-brand transition-colors duration-fast ease-out-quick
                          hover:bg-brand-hover active:bg-brand-active">
                    {{ __('errors.unavailable_invite') }}
                </a>
                <a href="{{ route('home') }}"
                   data-testid="error-retry"
                   class="underline text-ink-muted">
                    {{ __('errors.retry') }}
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
