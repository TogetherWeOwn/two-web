<x-layouts.app title="Privacy policy — Together We Own">
    <section class="bg-violet" aria-labelledby="privacy-heading">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <p class="text-xs font-bold uppercase tracking-widest text-ink-muted">Your data, in plain language</p>
            <h1 id="privacy-heading" class="u-display mt-3 text-3xl text-ink md:text-5xl">Privacy policy</h1>
            <p class="mt-3 text-sm text-ink-muted">Version {{ $policyVersion }}</p>

            <div data-testid="privacy-policy" class="policy-prose mt-8">
                {!! $policyHtml !!}
            </div>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ route('join') }}"
                   data-testid="privacy-join"
                   class="inline-flex min-h-11 items-center justify-center rounded-md bg-brand px-6
                          font-semibold text-on-brand transition-colors duration-fast ease-out-quick
                          hover:bg-brand-hover active:bg-brand-active">
                    Join with Discord
                </a>
                <a href="{{ route('home') }}"
                   class="underline text-ink-muted">
                    Back to the homepage
                </a>
            </div>
        </div>
    </section>
</x-layouts.app>
