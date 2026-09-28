{{--
    The past-events archive. Tokens only; no hex and no arbitrary values.

    The same cards as the calendar's past list (`partials.event-card` with
    `$isPast`, so no RSVP control), but paginated and on its own URL — the
    calendar's inline list stops at twenty with no way to reach older history.
--}}
<div class="mx-auto w-full max-w-6xl px-4 py-10 md:px-6 lg:px-8">
    <header>
        <a href="{{ route('events.index') }}"
           class="text-sm text-ink-muted hover:text-ink transition-colors duration-fast ease-out-quick">
            ← Upcoming events
        </a>
        <h1 class="u-display mt-2 text-3xl text-ink lg:text-4xl">Past events</h1>
        <p class="mt-1.5 max-w-prose text-ink-muted">
            Game nights and tournaments that already happened, most recent first.
        </p>
    </header>

    @if ($events->isEmpty())
        <div class="u-hatch mt-8 rounded-lg border border-line p-8 text-center"
             data-testid="past-events-empty">
            <h2 class="text-lg font-semibold text-ink">No past events yet.</h2>
            <p class="mx-auto mt-1.5 max-w-prose text-sm text-ink-muted">
                When a game night ends, it lands here. Until then, the upcoming
                calendar is where everything lives.
            </p>
            <div class="mt-5">
                <a href="{{ route('events.index') }}"
                   data-testid="past-events-back"
                   class="inline-flex items-center justify-center gap-2 min-h-11 px-6 rounded-md
                          bg-brand text-on-brand font-semibold
                          hover:bg-brand-hover active:bg-brand-active
                          transition-colors duration-fast ease-out-quick">
                    See upcoming events
                </a>
            </div>
        </div>
    @else
        <h2 class="sr-only">Past events, most recent first</h2>
        <ul class="mt-8 flex flex-col gap-4" role="list" data-testid="past-events-list">
            @foreach ($events as $event)
                <li>@include('partials.event-card', ['event' => $event, 'isPast' => true])</li>
            @endforeach
        </ul>

        @if ($events->hasPages())
            {{-- TOG-7332: pagination swaps the list without reloading, so the
                 page change has to be announced or a screen reader user is
                 moving blind through the archive. --}}
            <p class="sr-only" role="status" data-testid="past-events-page-status">
                Showing page {{ $events->currentPage() }} of {{ $events->lastPage() }}.
            </p>
            <nav class="mt-8" aria-label="Past events pages" data-testid="past-events-pagination">
                {{ $events->links() }}
            </nav>
        @endif
    @endif
</div>
