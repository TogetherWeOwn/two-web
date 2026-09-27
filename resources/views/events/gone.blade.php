{{--
    A cancelled event as a 410 Gone page (TOG-6781). The URL stays — shares and
    crawlers must be able to tell "called off" apart from "never existed" — but
    the status is 410 and the body says so, with no share tags and noindex so
    nothing re-indexes a dead event.
--}}
<x-layouts.app :title="$event->title.' — Cancelled — Together We Own'"
               robots="noindex, nofollow">
    <div class="mx-auto w-full max-w-3xl px-4 py-10 md:px-6 lg:px-8">
        <a href="{{ route('events.index') }}"
           class="text-sm text-ink-muted hover:text-ink transition-colors duration-fast ease-out-quick">
            ← All events
        </a>

        <article data-testid="event-gone" class="mt-4 rounded-lg bg-surface border border-line p-5 md:p-8">
            <h1 class="u-display text-3xl text-ink lg:text-4xl">{{ $event->title }}</h1>

            <p class="mt-4 max-w-prose text-ink-muted">
                This event was cancelled. It is not happening — this page stays up
                so links shared before the cancellation land somewhere honest
                instead of a 404.
            </p>
        </article>
    </div>
</x-layouts.app>
