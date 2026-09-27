{{--
    The events page. Tokens only; no hex and no arbitrary values.

    Mobile is the primary case — most people arrive from a link in Discord, on a
    phone, in the in-app browser — so the list is a single column at 360px and the
    month grid is behind a deliberate choice rather than the default.
--}}
<div class="mx-auto w-full max-w-6xl px-4 py-10 md:px-6 lg:px-8">
    <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
        <div>
            <h1 class="u-display text-3xl text-ink lg:text-4xl">Events</h1>
            <p class="mt-1.5 max-w-prose text-ink-muted">
                Game nights, tournaments and whatever else the community puts on.
            </p>
        </div>

        {{-- A radio group, not two buttons: it is one choice with two options, and
             that is what a screen reader should hear. The archive link sits
             beside it, not inside it — history is a destination, not a third
             view, and a link that acted as a radio option would lie about
             what it does. --}}
        <div class="flex items-center gap-3 self-start">
            <div class="flex items-center gap-1 rounded-md border border-line bg-surface p-1"
                 role="radiogroup" aria-label="How to show the events">
                @foreach (['list' => 'List', 'calendar' => 'Calendar'] as $key => $label)
                    <button type="button"
                            wire:click="setView('{{ $key }}')"
                            role="radio"
                            aria-checked="{{ $view === $key ? 'true' : 'false' }}"
                            data-testid="events-view-{{ $key }}"
                            @class([
                                'min-h-11 px-4 rounded-md text-sm font-medium transition-colors duration-fast ease-out-quick',
                                'bg-raised text-ink' => $view === $key,
                                'text-ink-muted hover:text-ink hover:bg-raised' => $view !== $key,
                            ])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <a href="{{ route('events.past') }}"
               data-testid="events-past-archive-link"
               class="inline-flex items-center min-h-11 px-4 rounded-md text-sm font-medium
                      text-ink-muted hover:text-ink hover:bg-raised
                      transition-colors duration-fast ease-out-quick">
                Past events
            </a>
        </div>
    </header>

    {{-- Search. Server-side: the query narrows the same rows the list and the
         grid render, and `?q=` stays in the URL so a search is a link a member
         can share. `live` with a debounce re-queries as the member types
         without turning each keystroke into a round trip. --}}
    <div class="mt-6 flex items-center gap-2" role="search">
        <label for="events-search" class="sr-only">Search events</label>
        <input id="events-search"
               type="search"
               wire:model.live.debounce.300ms="search"
               placeholder="Search events…"
               autocomplete="off"
               data-testid="events-search"
               class="min-h-11 w-full max-w-md rounded-md border border-line bg-surface px-3
                      text-ink placeholder:text-ink-muted" />
        @if ($searching)
            <button type="button"
                    wire:click="clearSearch"
                    data-testid="events-search-clear"
                    class="inline-flex shrink-0 items-center justify-center min-h-11 px-4 rounded-md
                           bg-transparent text-ink border border-line-strong
                           hover:bg-raised hover:border-ink-muted active:bg-surface
                           transition-colors duration-fast ease-out-quick">
                Clear
            </button>
        @endif
    </div>

    {{-- What a search found, in words. `{{ }}` escapes the query on the way
         out, so echoing it back here cannot become markup no matter what the
         URL carried. `aria-live` because the line changes without reloading. --}}
    @if ($searching && $emptyState !== 'error')
        <p class="mt-4 text-sm text-ink-muted" aria-live="polite" data-testid="events-search-status">
            @if ($hasVisibleResults)
                Results for &ldquo;{{ trim($search) }}&rdquo;
            @else
                Nothing matches &ldquo;{{ trim($search) }}&rdquo;.
            @endif
        </p>

        @unless ($hasVisibleResults)
            <div class="u-hatch mt-4 rounded-lg border border-line p-8 text-center"
                 data-testid="events-empty-search">
                <h2 class="text-lg font-semibold text-ink">Nothing matches that search.</h2>
                <p class="mx-auto mt-1.5 max-w-prose text-sm text-ink-muted">
                    Titles and descriptions are what's searched — try a different word.
                </p>
                <div class="mt-5">
                    <button type="button"
                            wire:click="clearSearch"
                            data-testid="events-search-clear-empty"
                            class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-md
                                   bg-transparent text-ink border border-line-strong
                                   hover:bg-raised hover:border-ink-muted active:bg-surface
                                   transition-colors duration-fast ease-out-quick">
                        Clear the search
                    </button>
                </div>
            </div>
        @endunless
    @endif

    {{-- ------------------------------------------------------------------
         Empty states. Three of them, and they say different things. The
         never-scheduled and the gap states are invitations; the error state
         is the only one that reads as broken, because it is.
         ------------------------------------------------------------------ --}}
    @if ($emptyState === 'never')
        <div class="u-hatch mt-8 rounded-lg border border-line p-8 text-center"
             data-testid="events-empty-never">
            <h2 class="text-lg font-semibold text-ink">Nothing on the calendar yet.</h2>
            <p class="mx-auto mt-1.5 max-w-prose text-sm text-ink-muted">
                Game nights get posted here first. Join the Discord and you'll see them
                before they land on this page.
            </p>
            <div class="mt-5">
                {{-- Route through /join (one-click OAuth + invite fallback), not the raw
                     /discord invite — matches every other visitor-facing join CTA. --}}
                <a href="{{ route('join') }}"
                   data-testid="discord-join"
                   class="inline-flex items-center justify-center gap-2 min-h-11 px-6 rounded-md
                          bg-brand text-on-brand font-semibold
                          hover:bg-brand-hover active:bg-brand-active
                          transition-colors duration-fast ease-out-quick">
                    Join the Discord
                </a>
            </div>
        </div>

    @elseif ($emptyState === 'gap')
        {{-- No upcoming events, but there were some: name the last one and show
             the recent history inline, so the page reads as "between game
             nights" rather than abandoned. --}}
        <div class="u-hatch mt-8 rounded-lg border border-line p-8 text-center"
             data-testid="events-empty-gap">
            <h2 class="text-lg font-semibold text-ink">No upcoming events — check back soon.</h2>
            <p class="mx-auto mt-1.5 max-w-prose text-sm text-ink-muted">
                Last time: {{ $lastPastEvent->title }} &middot; {{ $lastPastEvent->startsAtLocal()->format('D j M, H:i') }}.
            </p>
            <h3 class="mt-6 text-sm font-semibold text-ink">Past events</h3>
            <ul class="mx-auto mt-2 flex max-w-prose flex-col gap-1.5 text-left" role="list"
                data-testid="events-empty-gap-list">
                @foreach ($past->take(5) as $event)
                    <li data-testid="events-empty-gap-item"
                        class="flex items-baseline justify-between gap-4 rounded-md border border-line bg-surface px-4 py-2">
                        <span class="truncate text-sm text-ink">{{ $event->title }}</span>
                        <span class="u-numeric shrink-0 text-xs text-ink-muted">{{ $event->startsAtLocal()->format('D j M, H:i') }}</span>
                    </li>
                @endforeach
            </ul>
            <div class="mt-5">
                <a href="{{ route('discord') }}"
                   data-testid="discord-join"
                   class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-md
                          bg-transparent text-ink border border-line-strong
                          hover:bg-raised hover:border-ink-muted active:bg-surface
                          transition-colors duration-fast ease-out-quick">
                    Join the Discord
                </a>
            </div>
        </div>

    @elseif ($emptyState === 'error')
        {{-- The read failed: say so, offer the retry, and point at the Discord
             that always has the latest. This must never read as "no events". --}}
        <div class="u-hatch mt-8 rounded-lg border border-line p-8 text-center"
             data-testid="events-empty-error" role="alert">
            <h2 class="text-lg font-semibold text-ink">We couldn't load the calendar.</h2>
            <p class="mx-auto mt-1.5 max-w-prose text-sm text-ink-muted">
                The Discord always has the latest — come ask there.
            </p>
            <div class="mt-5 flex items-center justify-center gap-3">
                <button type="button"
                        wire:click="retryLoad"
                        data-testid="events-retry"
                        class="inline-flex items-center justify-center gap-2 min-h-11 px-6 rounded-md
                               bg-brand text-on-brand font-semibold
                               hover:bg-brand-hover active:bg-brand-active
                               transition-colors duration-fast ease-out-quick">
                    Retry
                </button>
                <a href="{{ route('discord') }}"
                   data-testid="discord-join"
                   class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-md
                          bg-transparent text-ink border border-line-strong
                          hover:bg-raised hover:border-ink-muted active:bg-surface
                          transition-colors duration-fast ease-out-quick">
                    Join the Discord
                </a>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------------
         List view
         ------------------------------------------------------------------ --}}
    @if ($view === 'list')
        @if ($upcoming->isNotEmpty())
            {{-- Not decoration, and not visible on purpose. "Past events" below is a
                 real h2, and without a matching one here the document went h1 -> h3
                 straight into the first event title: axe `heading-order`, and a
                 screen reader user tabbing by heading had no way to tell which list
                 they had landed in. The design has no upcoming heading — the page
                 title carries it visually — so the name exists for the accessibility
                 tree only. --}}
            <h2 class="sr-only">Upcoming events</h2>
            <ul class="mt-8 flex flex-col gap-4" role="list" data-testid="events-list">
                @foreach ($upcoming as $event)
                    <li>@include('partials.event-card', ['event' => $event, 'isPast' => false])</li>
                @endforeach
            </ul>
        @endif

        @if ($showPast && $past->isNotEmpty())
            <h2 class="mt-12 text-2xl text-ink">Past events</h2>
            <ul class="mt-4 flex flex-col gap-4" role="list" data-testid="events-past-list">
                @foreach ($past as $event)
                    <li>@include('partials.event-card', ['event' => $event, 'isPast' => true])</li>
                @endforeach
            </ul>
        @endif

    {{-- ------------------------------------------------------------------
         Calendar view. A real table, because a month grid is tabular data and
         a grid of divs announces nothing useful.

         Below md it scrolls horizontally inside its own container rather than
         squashing seven columns into 360px — a 40px-wide day cell is not a
         calendar, and it would break the reflow requirement for the page.
         ------------------------------------------------------------------ --}}
    @else
        <div class="mt-8 flex items-center justify-between gap-4">
            <button type="button"
                    wire:click="previousMonth"
                    aria-label="Previous month"
                    class="u-tap inline-flex items-center justify-center min-h-11 px-3 rounded-md
                           text-ink-muted hover:text-ink hover:bg-raised
                           transition-colors duration-fast ease-out-quick">
                <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M12.5 4 6.5 10l6 6 1.4-1.4L9.3 10l4.6-4.6L12.5 4Z"/>
                </svg>
            </button>

            {{-- aria-live: the month changes without the page reloading, so the
                 change has to be announced or a screen reader user is moving
                 blind through the grid. --}}
            <p class="text-lg font-semibold text-ink" aria-live="polite" data-testid="calendar-month">
                {{ $monthLabel }}
            </p>

            <button type="button"
                    wire:click="nextMonth"
                    aria-label="Next month"
                    class="u-tap inline-flex items-center justify-center min-h-11 px-3 rounded-md
                           text-ink-muted hover:text-ink hover:bg-raised
                           transition-colors duration-fast ease-out-quick">
                <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M7.5 4 6.1 5.4 10.7 10l-4.6 4.6L7.5 16l6-6-6-6Z"/>
                </svg>
            </button>
        </div>

        <div class="mt-4 overflow-x-auto"
             tabindex="0"
             aria-label="Events calendar; scroll horizontally to see all days"
             data-testid="events-calendar-scroll">
            <table class="w-full min-w-2xl table-fixed border-collapse"
                   data-testid="events-calendar-grid">
                <caption class="sr-only">Events in {{ $monthLabel }}</caption>
                <thead>
                    <tr>
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                            <th scope="col" class="p-2 text-xs font-medium text-ink-muted">
                                {{ $day }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($weeks as $week)
                        <tr>
                            @foreach ($week as $day)
                                <td @class([
                                        'h-24 align-top border border-line p-1.5',
                                        'bg-surface' => $day['inMonth'],
                                        'bg-canvas' => ! $day['inMonth'],
                                    ])
                                    data-testid="calendar-day"
                                    @if ($day['isToday']) aria-current="date" @endif>
                                    <span @class([
                                        'u-numeric text-xs',
                                        'text-ink font-semibold' => $day['isToday'],
                                        'text-ink-muted' => ! $day['isToday'],
                                    ])>
                                        {{ $day['date']->format('j') }}
                                    </span>

                                    @foreach ($day['events'] as $event)
                                        <a href="#event-{{ $event->event_key }}"
                                           wire:click="setView('list')"
                                           class="mt-1 block rounded-sm bg-brand-quiet px-1.5 py-0.5
                                                  text-xs text-brand-ink
                                                  hover:bg-raised transition-colors duration-fast ease-out-quick">
                                            {{ $event->startsAtLocal()->format('H:i') }}
                                            {{ \Illuminate\Support\Str::limit($event->title, 18) }}
                                        </a>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
