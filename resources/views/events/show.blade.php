{{--
    One event as a shareable page. Tokens only; no hex and no arbitrary values.

    This is the page a link shared in Discord lands on, so it is public: a
    signed-out visitor sees the event plus a pitch to join, never the OAuth
    handoff and never an RSVP button that cannot work. Signed-in members get
    the same RSVP control the calendar cards carry.
--}}
<x-layouts.app :title="$event->title.' — Together We Own'"
               :canonical="route('events.page', $event)"
               :shareDescription="$event->description ?: 'An event at Together We Own.'">
    <div class="mx-auto w-full max-w-3xl px-4 py-10 md:px-6 lg:px-8">
        <a href="{{ route('events.index') }}"
           class="text-sm text-ink-muted hover:text-ink transition-colors duration-fast ease-out-quick">
            ← All events
        </a>

        <article data-testid="event-page" class="mt-4 rounded-lg bg-surface border border-line p-5 md:p-8">
            {{-- Machine-readable event for crawlers: schema.org JSON-LD in the
                 body is valid and parsed by Google, and keeps this off the
                 shared layout that every other page hangs off. --}}
            <script type="application/ld+json" data-testid="event-jsonld">
                {!! json_encode(\App\Support\EventJsonLd::for($event), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
            </script>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="u-display text-3xl text-ink lg:text-4xl">{{ $event->title }}</h1>

                    @if ($event->game)
                        <p class="mt-1 text-ink-muted">{{ $event->game }}</p>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($event->status === \App\Enums\EventStatus::Draft)
                        <span class="inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                                     bg-alert-quiet text-alert" data-testid="event-draft">
                            Draft
                        </span>
                    @elseif ($event->status === \App\Enums\EventStatus::Cancelled)
                        <span class="inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                                     bg-alert-quiet text-alert" data-testid="event-cancelled">
                            Cancelled
                        </span>
                    @elseif ($event->status === \App\Enums\EventStatus::Past)
                        <span class="inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                                     bg-raised text-ink-muted border border-line" data-testid="event-past">
                            Past event
                        </span>
                    @endif

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

            {{-- The machine-readable instant and the human one: `datetime` carries
                 the offset while the visible text is the wall clock in the zone
                 the host chose. Same contract as the calendar cards. --}}
            <p class="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-muted">
                <time datetime="{{ $event->starts_at->toIso8601String() }}" class="u-numeric text-ink">
                    {{ $event->startsAtLocal()->format('D j M, H:i') }}
                </time>
                <span aria-hidden="true">·</span>
                <span class="u-numeric">{{ $event->endsAtLocal()->format('H:i') }}</span>
                <span class="text-xs">{{ $event->timezone }}</span>
            </p>

            @if ($event->location)
                <p class="mt-2 text-sm text-ink-muted" data-testid="event-venue">
                    {{ $event->location }}
                </p>
            @endif

            @if ($event->description)
                <p class="mt-4 max-w-prose text-ink-muted">{{ $event->description }}</p>
            @endif

            <div class="mt-6 border-t border-line pt-6">
                @auth
                    <livewire:rsvp-button :event="$event" />
                @endauth

                @guest
                    {{-- Not a disabled RSVP button. A guest's next action is to join,
                         and the events index makes the same pitch the same way. --}}
                    <div data-testid="event-join-pitch">
                        <p class="max-w-prose text-sm text-ink-muted">
                            Game nights get posted here first. Join the Discord and you'll
                            see them before they land on this page.
                        </p>
                        <a href="{{ route('join') }}"
                           data-testid="discord-join"
                           class="mt-4 inline-flex items-center justify-center gap-2 min-h-11 px-6 rounded-md
                                  bg-brand text-on-brand font-semibold
                                  hover:bg-brand-hover active:bg-brand-active
                                  transition-colors duration-fast ease-out-quick">
                            Join the Discord
                        </a>
                    </div>
                @endguest
            </div>
        </article>
    </div>
</x-layouts.app>
