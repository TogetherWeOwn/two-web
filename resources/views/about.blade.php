<x-layouts.app title="About — Together We Own">
    <section class="bg-violet" aria-labelledby="about-heading">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <p class="text-xs font-bold uppercase tracking-widest text-ink-muted">Est. 1998</p>
            <h1 id="about-heading" class="u-display mt-3 text-3xl text-ink md:text-5xl">About Together We Own</h1>
            <p class="mt-3 max-w-prose text-lg text-ink-muted">An adult gaming community that spent most of its life private. Now the doors are open: turn up, say hello, come back.</p>

            <dl data-testid="about-facts" class="mt-8 space-y-4">
                <div class="rounded-lg border border-line bg-surface p-4">
                    <dt class="font-semibold text-ink">Voice-first</dt>
                    <dd class="mt-1 text-ink-muted">The community lives in voice. Turn up, say hello, and come back — that is the whole membership path.</dd>
                </div>
                <div class="rounded-lg border border-line bg-surface p-4">
                    <dt class="font-semibold text-ink">No application, no interview</dt>
                    <dd class="mt-1 text-ink-muted">You start as a Prospect. Show up a few times, play, become a Member. The ladder records trust and time, not grind.</dd>
                </div>
                <div class="rounded-lg border border-line bg-surface p-4">
                    <dt class="font-semibold text-ink">From forum threads to voice rooms</dt>
                    <dd class="mt-1 text-ink-muted">Founded in 1998. Forum years, then voice years — duos, trios, quads, squads. Today: doors open.</dd>
                </div>
            </dl>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ route('join') }}"
                   data-testid="about-join"
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
