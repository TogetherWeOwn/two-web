{{--
    Branded 500 (TOG-5626). Laravel renders this view automatically for
    unhandled exceptions when debug is off — no handler code needed.

    Never echoes the exception: the message and trace stay in the logs, never
    in a member's browser. Database-free by construction, because the database
    may be exactly what is broken.
--}}
<x-layouts.app title="{{ __('errors.error_title') }} — {{ config('app.name') }}" robots="noindex, nofollow">
    <section class="bg-violet">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <p class="text-sm font-bold uppercase tracking-widest text-ink-muted" aria-hidden="true">500</p>
            <h1 class="u-display mt-2 text-3xl text-ink md:text-5xl">{{ __('errors.error_title') }}</h1>
            <p class="mt-3 max-w-prose text-lg text-ink-muted">{{ __('errors.error_body') }}</p>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ route('join') }}"
                   data-testid="discord-join"
                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-brand px-6
                          font-semibold text-on-brand transition-colors duration-fast ease-out-quick
                          hover:bg-brand-hover active:bg-brand-active">
                    {{ __('errors.error_join') }}
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
