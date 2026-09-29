{{--
    Branded 404 (TOG-5626, suggestions in TOG-6929). Laravel renders this view
    automatically for any NotFoundHttpException — no route or handler needed,
    and no fallback route that could soft-404
    (see tests/Feature/NotFoundTest.php).

    Keeps the join CTA because a lost visitor is still a potential member, and
    suggests up to 3 upcoming events plus a search form pointing at /events so
    the page is a signpost instead of a dead end. `$suggestedEvents` arrives
    from the view composer in AppServiceProvider, which swallows database
    failures — a 404 must never become a 500 just because the database is
    down. Drafts are excluded (moderators excepted) by the same rule the
    listing enforces.
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

            <div class="mt-10" data-testid="error-event-suggestions">
                <h2 class="text-lg font-semibold text-ink">{{ __('errors.not_found_suggestions') }}</h2>

                @if (($suggestedEvents ?? collect())->isNotEmpty())
                    <ul class="mt-4 space-y-3">
                        @foreach ($suggestedEvents as $suggestedEvent)
                            <li>
                                <a href="{{ route('events.page', $suggestedEvent) }}"
                                   data-testid="error-event-suggestion"
                                   class="block rounded-lg border border-line bg-surface p-4
                                          transition-colors duration-fast ease-out-quick hover:bg-raised">
                                    <span class="font-semibold text-ink">{{ $suggestedEvent->title }}</span>
                                    <span class="mt-0.5 block text-sm text-ink-muted">
                                        <time datetime="{{ $suggestedEvent->starts_at->toIso8601String() }}">
                                            {{ $suggestedEvent->startsAtLocal()->format('D j M, H:i') }}
                                        </time>
                                        @if ($suggestedEvent->location)
                                            <span aria-hidden="true"> · </span>{{ $suggestedEvent->location }}
                                        @endif
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 text-ink-muted" data-testid="error-events-empty">
                        {{ __('errors.not_found_empty') }}
                    </p>
                @endif

                <div class="mt-4 flex flex-wrap items-center gap-4">
                    <a href="{{ route('events.index') }}"
                       data-testid="error-all-events"
                       class="underline text-ink-muted">
                        {{ __('errors.not_found_all_events') }}
                    </a>

                    {{-- Plain GET form, not Livewire: the event search lives in a
                         Livewire component the error page must not boot. `q` is the
                         same query param the calendar binds to the URL, so the form
                         lands on a shareable search. --}}
                    <form action="{{ route('events.index') }}" method="get" role="search"
                          class="flex items-center gap-2">
                        <label for="error-events-search" class="sr-only">{{ __('errors.not_found_search') }}</label>
                        <input id="error-events-search"
                               name="q"
                               type="search"
                               placeholder="{{ __('errors.not_found_search_placeholder') }}"
                               autocomplete="off"
                               data-testid="error-events-search"
                               class="min-h-11 rounded-md border border-line bg-surface px-3
                                      text-ink placeholder:text-ink-muted" />
                        <button type="submit"
                                data-testid="error-events-search-submit"
                                class="inline-flex min-h-11 items-center justify-center rounded-md px-4
                                       bg-transparent text-ink border border-line-strong
                                       hover:bg-raised active:bg-surface
                                       transition-colors duration-fast ease-out-quick">
                            {{ __('errors.not_found_search') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-layouts.app>
