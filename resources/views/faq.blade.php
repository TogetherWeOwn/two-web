{{--
    Static FAQ page (TOG-8396, entries TOG-8863). A session-free,
    database-free leaf in routes/funnel.php: `Route::view` only, no
    controller, no query, no Livewire — it must stay 200 during an app-DB
    outage. It renders from App\Support\FaqEntry (the single source of truth
    for every entry: stable slug, section, question, answer paragraphs), so
    rewording a question keeps its vote history.

    Voting is progressive enhancement only (TOG-8863): the page renders
    identically without JS and calls the `web`-group vote endpoints from
    resources/js/faq-votes.js. Every entry card carries
    `data-faq-entry="<slug>"` and a vote row that stays `hidden` until the
    module boots — if the backend is unreachable the rows stay hidden rather
    than showing buttons that cannot work. `connect-src 'self'` already covers
    the same-origin fetch, so the pinned CSP is untouched.
--}}
<x-layouts.app title="FAQ — Together We Own">
    <section class="bg-violet" aria-labelledby="faq-heading">
        <div class="mx-auto max-w-3xl px-4 py-16 md:px-6 md:py-20 lg:px-8">
            <p class="text-xs font-bold uppercase tracking-widest text-ink-muted">New here? Start here</p>
            <h1 id="faq-heading" class="u-display mt-3 text-3xl text-ink md:text-5xl">Frequently asked questions</h1>
            <p class="mt-3 max-w-prose text-lg text-ink-muted">Short answers to what newcomers actually ask. If yours isn&rsquo;t here, ask in general or DM a moderator &mdash; there are no stupid questions in week one.</p>

            <div data-testid="faq-list" data-faq-votes="{{ route('faq.votes.index') }}" class="mt-8 space-y-8">
                @foreach (\App\Support\FaqEntry::grouped() as $group)
                    <section aria-labelledby="{{ $group['anchor'] }}">
                        <h2 id="{{ $group['anchor'] }}" class="text-xl font-bold text-ink">{{ $group['title'] }}</h2>
                        <div class="mt-4 space-y-4">
                            @foreach ($group['entries'] as $entry)
                                <div class="rounded-lg border border-line bg-surface p-4" data-faq-entry="{{ $entry->value }}">
                                    <h3 class="font-semibold text-ink">{!! $entry->question() !!}</h3>
                                    @foreach ($entry->answerParagraphs() as $paragraph)
                                        <p class="mt-1 text-ink-muted">{!! $paragraph !!}</p>
                                    @endforeach
                                    {{-- Was-this-helpful (TOG-8863). Hidden until
                                         faq-votes.js boots: no-JS and backend-down
                                         visitors see the static answer only. --}}
                                    <div class="mt-3 hidden border-t border-line pt-3" data-faq-vote>
                                        <p class="text-sm text-ink-muted" data-faq-vote-prompt>
                                            Was this helpful?
                                            <button type="button" data-faq-vote-yes aria-pressed="false" class="ml-2 underline hover:text-ink">Yes</button>
                                            <span aria-hidden="true"> / </span>
                                            <button type="button" data-faq-vote-no aria-pressed="false" class="underline hover:text-ink">No</button>
                                        </p>
                                        <p class="hidden text-sm text-ink-muted" data-faq-vote-thanks role="status"></p>
                                        <p class="hidden text-sm text-alert" data-faq-vote-error role="alert"></p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>

            <div class="mt-8 flex flex-wrap items-center gap-4">
                <a href="{{ route('join') }}"
                   data-testid="faq-join"
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
    {{--
        Page script (TOG-8863). A second @vite is fine — the plugin emits tags
        wherever the directive sits — and `type="module"` defers by default, so
        this never blocks first paint. Kept off the layout on purpose: the global
        bundle is pinned import-free (see AssetCompressionTest) and only FAQ
        pages should download this.
    --}}
    @vite('resources/js/faq-votes.js')
</x-layouts.app>
