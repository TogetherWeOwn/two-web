{{--
    One event, in the list, for a signed-out guest. The static twin of
    `partials.event-card` (TOG-9277): the same markup, but nothing that needs
    Livewire. The going badge is a plain span and the RSVP control is the
    login link, so the whole card is cacheable HTML with no `wire:id`s to go
    stale or collide between guests.

    Keep the two in step: same article id (the grid links at `#event-…`),
    same testids, same copy, same tokens. What differs is load-bearing:

    - no `role="status"` on the badge: it never updates in place, so there is
      nothing to announce. (The live badge's status role belongs to the
      component that re-reads the aggregate.)
    - no `showSpotsLeft`: that signal is specific to the shareable page, which
      never renders this partial.
    - `$returnTo` (the `?next=` page path, or null for a bare link) arrives as
      a parameter, because a cached fragment cannot read the request.

    @param \App\Models\Event $event
    @param bool $isPast
    @param string|null $returnTo
--}}
@php($isPast = $isPast ?? false)
@php($returnTo = $returnTo ?? null)
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

            {{-- The count, frozen at render. Same tokens as the live badge
                 (`rounded-sm bg-raised text-ink-muted border border-line`,
                 tabular figures so it does not jitter) minus the live region:
                 this span never changes without a re-render. Omitted when
                 unknown, same as the live card: a Discord-native row has no
                 local answers, and "0 going" would be a number we do not know. --}}
            @if ($event->going_count !== null)
                <span class="u-numeric inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                             bg-raised text-ink-muted border border-line"
                      data-testid="event-going-count">
                    @if ($event->capacity !== null)
                        {{ $event->going_count }} of {{ $event->capacity }} going
                    @else
                        {{ $event->going_count }} going
                    @endif
                </span>
            @endif
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
            @if ($event->exists)
                {{-- Not a disabled RSVP button. A guest's next action is to log in, and
                     saying so is shorter than explaining why the button is grey.
                     `?next=` returns them to this page after Discord (TOG-9254);
                     `$returnTo` is the cached page path, null for a bare link. --}}
                <a href="{{ route('login', $returnTo ? ['next' => $returnTo] : []) }}"
                   class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-md
                          bg-transparent text-ink border border-line-strong
                          hover:bg-raised hover:border-ink-muted active:bg-surface
                          transition-colors duration-fast ease-out-quick">
                    Log in with Discord
                </a>
            @else
                {{-- A Discord-native row has no local answers to record — the
                     answer happens in Discord, so the card links there instead
                     of rendering a control that cannot work. Unreachable
                     through `AnonymousEventCard::render` (transients always
                     render fresh), kept so the partial is correct standalone. --}}
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
