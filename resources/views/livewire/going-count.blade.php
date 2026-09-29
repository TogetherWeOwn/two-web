{{--
    The "N of M going" badge. Rendered by the GoingCount component so it can
    re-read the aggregate when RsvpButton broadcasts `going-count-updated`
    after a successful write (TOG-7966). Same tokens as the card it sits in:
    `rounded-sm bg-raised text-ink-muted border border-line`, tabular figures
    so it does not jitter as it ticks.

    `role="status"`: the count changes without a reload, so the change is
    announced politely. The visually-hidden prefix names what just happened
    ("You're going. 4 of 20 going.") because a bare number gives a screen
    reader user no reason for the change. Empty before any write, so the
    initial page load announces nothing.

    One directive per line: Blade does not recognise `@endif` glued to the
    next directive (`@endif@if` leaves the first `@if` unclosed and the
    events page 500s). That is what took CI red on PR #435.

    `showSpotsLeft` (TOG-6924): the shareable page also wants "N of M spots
    left" / "Full" from the same live `$going`/`$capacity` this badge already
    tracks — no separate query, no separate broadcast to listen for. Unknown
    capacity renders nothing rather than inventing a number.

    Livewire allows exactly one root element per component, so the spots-left
    span can't sit beside the going-count span as a sibling — both live inside
    one `display: contents` wrapper, which is transparent to layout.
--}}
<div class="contents">
    <span class="u-numeric inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                 bg-raised text-ink-muted border border-line"
          role="status"
          data-testid="event-going-count">
        @if ($announcementText !== '')
            <span class="sr-only">{{ $announcementText }} </span>
        @endif
        @if ($capacity !== null)
            {{ $going }} of {{ $capacity }} going
        @else
            {{ $going }} going
        @endif
    </span>
    @if ($showSpotsLeft && $capacity !== null)
        @php($spotsLeft = max(0, $capacity - $going))
        @if ($spotsLeft <= 0)
            <span class="u-numeric inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                         bg-alert-quiet text-alert"
                  data-testid="event-spots-left">
                Full
            </span>
        @else
            <span class="u-numeric inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                         bg-raised text-ink-muted border border-line"
                  data-testid="event-spots-left">
                {{ $spotsLeft }} of {{ $capacity }} spots left
            </span>
        @endif
    @endif
</div>
