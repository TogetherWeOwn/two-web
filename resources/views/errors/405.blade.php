{{--
    Branded 405 (TOG-8562). Laravel renders this view automatically for any
    MethodNotAllowedHttpException on a browser request — no route or handler
    needed. The main trigger is GET /logout: logout is POST-only by design
    (routes/web.php) so an <img> tag can never sign a member out, and a stale
    link, bookmark, prefetch, or crawler lands here instead of on the
    framework-default page.

    Names the POST-only rule and points at /profile, where the real Sign out
    POST form lives. Database-free by construction: only route names and lang
    lines, so this renders even when the database is down.
--}}
<x-layouts.app title="{{ __('errors.method_not_allowed_title') }} — {{ config('app.name') }}" robots="noindex, nofollow">
    <section class="bg-violet">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <p class="text-sm font-bold uppercase tracking-widest text-ink-muted" aria-hidden="true">405</p>
            <h1 class="u-display mt-2 text-3xl text-ink md:text-5xl">{{ __('errors.method_not_allowed_title') }}</h1>
            <p class="mt-3 max-w-prose text-lg text-ink-muted">{{ __('errors.method_not_allowed_body') }}</p>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ route('profile') }}"
                   data-testid="error-profile"
                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-brand px-6
                          font-semibold text-on-brand transition-colors duration-fast ease-out-quick
                          hover:bg-brand-hover active:bg-brand-active">
                    {{ __('errors.method_not_allowed_profile') }}
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
