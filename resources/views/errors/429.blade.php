{{--
    Branded 429 (TOG-6788). Laravel renders this view automatically for any
    ThrottleRequestsException on a browser request — see
    App\Support\ThrottleEnvelope, which routes HTML here and JSON callers to
    the `{reason, message, retry_after}` envelope so every throttle answers
    the same way.

    Keeps the join CTA because a throttled visitor is still a potential member.
    Database-free by construction: only route names and lang lines, so this
    renders even when the throttle fired before any controller ran.
--}}
<x-layouts.app title="{{ __('errors.too_many_title') }} — {{ config('app.name') }}" robots="noindex, nofollow">
    <section class="bg-violet">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <p class="text-sm font-bold uppercase tracking-widest text-ink-muted" aria-hidden="true">429</p>
            <h1 class="u-display mt-2 text-3xl text-ink md:text-5xl">{{ __('errors.too_many_title') }}</h1>
            <p class="mt-3 max-w-prose text-lg text-ink-muted">{{ __('errors.too_many_body') }}</p>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ route('join') }}"
                   data-testid="discord-join"
                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-brand px-6
                          font-semibold text-on-brand transition-colors duration-fast ease-out-quick
                          hover:bg-brand-hover active:bg-brand-active">
                    {{ __('errors.too_many_join') }}
                </a>
                <a href="{{ route('home') }}"
                   data-testid="error-home"
                   class="underline text-ink-muted">
                    {{ __('errors.home') }}
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
