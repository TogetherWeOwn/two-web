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

    A Discord-native row (TOG-5168) is a transient the reader built from the
    bot's `web_v1.upcoming_events`: no local answers exist, so `going_count`
    is null and the card omits the badge rather than publishing "0 going";
    and there is no local row to answer on, so the RSVP control is replaced by
    the Discord invite. `$event->exists` is the discriminator — it is false
    only for those transients, never for a persisted row.
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

            {{-- The count, live: GoingCount re-reads the aggregate when RsvpButton
                 broadcasts `going-count-updated` after a write (TOG-7966).
                 Omitted when unknown: a Discord-native row has no local answers,
                 and "0 going" would be a number we do not know. --}}
            @if ($event->going_count !== null)
                @livewire('going-count', ['event' => $event], key('going-count-'.$event->event_key))
            @endif
        </div>
    </div>

    {{-- The machine-readable instant and the human one. `datetime` carries the
         offset so a crawler and a screen reader both get an unambiguous time,
         while the visible text is the wall clock in the zone the host chose.
         The abbreviation and offset ride visibly (TOG-6806): on the 2026-10-25
         Europe/London autumn fold both 00:30Z and 01:30Z read as "01:30"
         locally, and without "BST (+01:00)" vs "GMT (+00:00)" the two cards
         are identical. The end carries its own suffix because an event can
         span the fold (BST start, GMT end). --}}
    <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-muted">
        <time datetime="{{ $event->starts_at->toIso8601String() }}" class="u-numeric text-ink">
            {{ $event->startsAtLocal()->format('D j M, H:i T (P)') }}
        </time>
        <span aria-hidden="true">·</span>
        <span class="u-numeric">{{ $event->endsAtLocal()->format('H:i T (P)') }}</span>
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
            @if ($event->exists)
                @livewire('rsvp-button', ['event' => $event], key($event->event_key))
            @else
                {{-- A Discord-native row has no local answers to record — the
                     answer happens in Discord, so the card links there instead
                     of rendering a control that cannot work. --}}
                <a href="{{ route('discord') }}"
                   data-testid="event-discord-rsvp"
                   class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-md
                          bg-transparent text-ink border border-line-strong
                          hover:bg-raised hover:border-ink-muted active:bg-surface
                          transition-colors duration-fast ease-out-quick">
                    RSVP in Discord
                </a>
            @endif
        </div>
    @endunless
</article>
