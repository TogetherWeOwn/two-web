<x-layouts.app title="House rules — Together We Own">
    <section class="bg-violet" aria-labelledby="rules-heading">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <h1 id="rules-heading" class="u-display text-3xl text-ink md:text-5xl">House rules</h1>
            <p class="mt-3 max-w-prose text-lg text-ink-muted">Five rules that keep the lobby a place people come back to. Short on purpose — if anything is unclear, ask in Discord before you assume.</p>
            <p data-testid="rules-last-updated" class="mt-2 text-sm text-ink-muted">Last updated <time datetime="{{ config('community.rules_last_updated') }}">{{ \Carbon\Carbon::parse(config('community.rules_last_updated'))->format('j F Y') }}</time></p>

            <ol data-testid="rules-list" class="mt-8 space-y-4">
                <li class="rounded-lg border border-line bg-surface p-4">
                    <h2 class="font-semibold text-ink">18+ only</h2>
                    <p class="mt-1 text-ink-muted">Together We Own is an adult gaming community. If you are under 18, this is not your lobby yet.</p>
                </li>
                <li class="rounded-lg border border-line bg-surface p-4">
                    <h2 class="font-semibold text-ink">Respect the room</h2>
                    <p class="mt-1 text-ink-muted">No harassment, hate, or punching down. Argue about games all you like; never about people.</p>
                </li>
                <li class="rounded-lg border border-line bg-surface p-4">
                    <h2 class="font-semibold text-ink">Voice-first</h2>
                    <p class="mt-1 text-ink-muted">The community lives in voice. Turn up, say hello, and come back — that is the whole membership path.</p>
                </li>
                <li class="rounded-lg border border-line bg-surface p-4">
                    <h2 class="font-semibold text-ink">Play fair</h2>
                    <p class="mt-1 text-ink-muted">No cheating, exploits, or griefing. Do not spoil the game for the people you share it with.</p>
                </li>
                <li class="rounded-lg border border-line bg-surface p-4">
                    <h2 class="font-semibold text-ink">Moderators have the last word</h2>
                    <p class="mt-1 text-ink-muted">If a moderator asks you to stop, stop. Appeals happen in private, not in the lobby.</p>
                </li>
            </ol>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ route('join') }}"
                   data-testid="rules-join"
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
