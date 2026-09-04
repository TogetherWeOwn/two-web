{{--
    One event, in the list. COMPONENTS.md §5: `rounded-lg bg-surface border
    border-line p-5`, no shadow, and no hover reaction — this card is not a link,
    and a card that highlights but cannot be clicked is a lie.

    @param \App\Models\Event $event
    @param bool $isPast

    The flag is `$isPast`, not `$past`, and the name is load-bearing. `@include`
    does not open a new scope — it merges the parent's variables — and the parent
    already has a `$past` holding the *collection* of past events. A collection is
    truthy, so `$past ?? false` silently resolved to it and every card in the
    upcoming list rendered with no RSVP button at all.
--}}
@php($isPast = $isPast ?? false)
<article id="event-{{ $event->event_key }}"
         data-event-key="{{ $event->event_key }}"
         data-testid="event-card"
         {{-- The sticky header must not cover this when it is jumped to from the
              calendar grid (WCAG 2.2 2.4.11, focus not obscured). --}}
         class="scroll-mt-20 rounded-lg bg-surface border border-line p-5">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="text-lg font-semibold text-ink">{{ $event->title }}</h3>

            @if ($event->game)
                <p class="mt-0.5 text-sm text-ink-muted">{{ $event->game }}</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($event->status === \App\Enums\EventStatus::Draft)
                <span class="inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                             bg-alert-quiet text-alert" data-testid="event-draft">
                    Draft
                </span>
            @elseif ($event->status === \App\Enums\EventStatus::Cancelled)
                {{-- Kept on the page rather than removed. Somebody RSVP'd to this. --}}
                <span class="inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                             bg-alert-quiet text-alert" data-testid="event-cancelled">
                    Cancelled
                </span>
            @endif

            {{-- The count, in tabular figures so it does not jitter as it ticks. --}}
            <span class="u-numeric inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                         bg-raised text-ink-muted border border-line"
                  data-testid="event-going-count">
                @if ($event->capacity !== null)
                    {{ $event->going_count }} of {{ $event->capacity }} going
                @else
                    {{ $event->going_count }} going
                @endif
            </span>
        </div>
    </div>

    {{-- The machine-readable instant and the human one. `datetime` carries the
         offset so a crawler and a screen reader both get an unambiguous time,
         while the visible text is the wall clock in the zone the host chose. --}}
    <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-muted">
        <time datetime="{{ $event->starts_at->toIso8601String() }}" class="u-numeric text-ink">
            {{ $event->startsAtLocal()->format('D j M, H:i') }}
        </time>
        <span aria-hidden="true">·</span>
        <span class="u-numeric">{{ $event->endsAtLocal()->format('H:i') }}</span>
        <span class="text-xs">{{ $event->timezone }}</span>
        @if ($event->location)
            <span aria-hidden="true">·</span>
            <span>{{ $event->location }}</span>
        @endif
    </p>

    @if ($event->description)
        <p class="mt-3 max-w-prose text-ink-muted">{{ $event->description }}</p>
    @endif

    @unless ($isPast)
        <div class="mt-4">
            @livewire('rsvp-button', ['event' => $event], key($event->event_key))
        </div>
    @endunless
</article>
