<x-layouts.app :title="$title">
    {{--
        OAuth recovery page (TOG-5606). Discord sent the member back with an
        `error` query param instead of a code — most often `access_denied`
        after pressing Cancel on the consent screen. This is a page, not a
        flash banner, because a banner on a busy landing page is easy to miss
        after a round trip to Discord and back.

        The `error_description` and `error_uri` params Discord sends are never
        rendered: they are third-party strings and not ours to echo.
    --}}
    <section class="bg-violet">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <h1 class="u-display text-3xl text-ink md:text-5xl">{{ $title }}</h1>
            <p class="mt-6 rounded-lg border border-line bg-surface p-4 text-ink"
               role="alert"
               data-testid="oauth-recovery">
                {{ $message }}
            </p>
            <div class="mt-8">
                <a href="{{ $retryUrl }}"
                   data-testid="oauth-recovery-retry"
                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-brand px-6
                          font-semibold text-on-brand transition-colors duration-fast ease-out-quick
                          hover:bg-brand-hover active:bg-brand-active">
                    {{ $retryLabel }}
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
