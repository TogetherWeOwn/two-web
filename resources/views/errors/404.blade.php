{{--
    Branded 404 (TOG-5626). Laravel renders this view automatically for any
    NotFoundHttpException — no route or handler needed, and no fallback route
    that could soft-404 (see tests/Feature/NotFoundTest.php).

    Keeps the join CTA because a lost visitor is still a potential member.
    Database-free by construction: only route names and lang lines.
--}}
<x-layouts.app title="{{ __('errors.not_found_title') }} — {{ config('app.name') }}" robots="noindex, nofollow">
    <section class="bg-violet">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <p class="text-sm font-bold uppercase tracking-widest text-ink-muted" aria-hidden="true">404</p>
            <h1 class="u-display mt-2 text-3xl text-ink md:text-5xl">{{ __('errors.not_found_title') }}</h1>
            <p class="mt-3 max-w-prose text-lg text-ink-muted">{{ __('errors.not_found_body') }}</p>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ route('join') }}"
                   data-testid="discord-join"
                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-brand px-6
                          font-semibold text-on-brand transition-colors duration-fast ease-out-quick
                          hover:bg-brand-hover active:bg-brand-active">
                    {{ __('errors.not_found_join') }}
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
