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
                 shared layout that every other page hangs off. Slashes and
                 unicode stay readable; `<`, `>`, `&`, quotes are hex-escaped
                 so a title containing `</script>` cannot break out of this
                 block — titles are free text. --}}
            <script type="application/ld+json" data-testid="event-jsonld">
                {!! json_encode(\App\Support\EventJsonLd::for($event), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}
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

                    {{-- Moderator-only audience count (TOG-8408): guests get the
                         going count plus the join pitch, never this number —
                         view totals are a moderation signal, not social proof. --}}
                    @can('viewDrafts', \App\Models\Event::class)
                        <span class="u-numeric inline-flex items-center gap-1.5 rounded-sm px-2 py-0.5 text-xs font-medium
                                     bg-raised text-ink-muted border border-line"
                              data-testid="event-view-count">
                            {{ $event->view_count ?? 0 }} {{ ($event->view_count ?? 0) === 1 ? 'view' : 'views' }}
                        </span>
                    @endcan

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

            {{-- Calendar export, side by side: the ICS download for every client
                 and the Google one-click for the member who lives in a browser.
                 Above the RSVP divider on purpose — saving the date is not RSVPing,
                 and a guest who cannot RSVP can still add the event. --}}
            <div class="mt-4 flex flex-wrap items-center gap-2" data-testid="event-calendar-links">
                <a href="{{ route('events.ics', $event) }}"
                   data-testid="event-ics"
                   class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-md
                          bg-transparent text-ink border border-line-strong
                          hover:bg-raised hover:border-ink-muted active:bg-surface
                          transition-colors duration-fast ease-out-quick">
                    Add to calendar (.ics)
                </a>
                <a href="{{ \App\Support\EventGoogleCalendar::url($event) }}"
                   data-testid="event-google-calendar"
                   target="_blank"
                   rel="noopener"
                   class="inline-flex items-center justify-center gap-2 min-h-11 px-5 rounded-md
                          bg-transparent text-ink border border-line-strong
                          hover:bg-raised hover:border-ink-muted active:bg-surface
                          transition-colors duration-fast ease-out-quick">
                    Add to Google Calendar
                </a>
            </div>

            {{-- Who's going: member display names for signed-in viewers only.
                 Guests see the count in the header plus the join pitch below —
                 no member-identifying data for logged-out visitors (TOG-5621).
                 Names only, no profile links (TOG-6926 owns that). --}}
            @auth
                @if ($attendees->isNotEmpty())
                    <div class="mt-6 border-t border-line pt-6" data-testid="event-attendees">
                        <h2 class="text-sm font-semibold text-ink">
                            Who's going ({{ $attendees->count() }})
                        </h2>
                        <ul class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($attendees as $name)
                                <li class="inline-flex items-center rounded-sm px-2 py-0.5 text-xs font-medium
                                           bg-raised text-ink-muted border border-line">
                                    {{ $name }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endauth

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

        {{-- Prev/next event, in `starts_at` order. The ends of the line omit
             their missing side rather than rendering a dead link — the first
             event has no previous, the last has no next. --}}
        @if ($previousEvent || $nextEvent)
            <nav class="mt-6 flex items-stretch justify-between gap-3" aria-label="More events"
                 data-testid="event-pagination">
                @if ($previousEvent)
                    <a href="{{ route('events.page', $previousEvent) }}"
                       data-testid="event-previous"
                       rel="prev"
                       class="flex-1 rounded-lg bg-surface border border-line p-4
                              hover:bg-raised transition-colors duration-fast ease-out-quick">
                        <span class="block text-xs text-ink-muted">← Previous event</span>
                        <span class="mt-1 block truncate text-sm font-medium text-ink">{{ $previousEvent->title }}</span>
                    </a>
                @else
                    <span class="flex-1" aria-hidden="true"></span>
                @endif

                @if ($nextEvent)
                    <a href="{{ route('events.page', $nextEvent) }}"
                       data-testid="event-next"
                       rel="next"
                       class="flex-1 rounded-lg bg-surface border border-line p-4 text-right
                              hover:bg-raised transition-colors duration-fast ease-out-quick">
                        <span class="block text-xs text-ink-muted">Next event →</span>
                        <span class="mt-1 block truncate text-sm font-medium text-ink">{{ $nextEvent->title }}</span>
                    </a>
                @else
                    <span class="flex-1" aria-hidden="true"></span>
                @endif
            </nav>
        @endif

        {{-- Related events: the next step for a visitor who will not RSVP to
             this one. Same game first, then the nearest other upcoming events,
             at most 3 — see EventPageController::relatedEvents(). Hidden
             entirely when there are no siblings: an empty "related" heading
             with nothing under it is worse than nothing. Guests get the join
             pitch with it, members already have the RSVP control above. --}}
        @if ($relatedEvents->isNotEmpty())
            <section class="mt-6 rounded-lg bg-surface border border-line p-5 md:p-8"
                     aria-label="Related events"
                     data-testid="event-related">
                <h2 class="text-sm font-semibold text-ink">
                    More events you might like
                </h2>
                <ul class="mt-3 space-y-3">
                    @foreach ($relatedEvents as $relatedEvent)
                        <li>
                            <a href="{{ route('events.page', $relatedEvent) }}"
                               data-testid="event-related-link"
                               class="block rounded-lg border border-line bg-surface p-4
                                      hover:bg-raised transition-colors duration-fast ease-out-quick">
                                <span class="block truncate text-sm font-medium text-ink">{{ $relatedEvent->title }}</span>
                                <span class="mt-0.5 block text-sm text-ink-muted">
                                    <time datetime="{{ $relatedEvent->starts_at->toIso8601String() }}" class="u-numeric">
                                        {{ $relatedEvent->startsAtLocal()->format('D j M, H:i') }}
                                    </time>
                                    @if ($relatedEvent->location)
                                        <span aria-hidden="true"> · </span>{{ $relatedEvent->location }}
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>

                @guest
                    <p class="mt-4 max-w-prose text-sm text-ink-muted">
                        These fill up fast for members. Join the Discord and you'll
                        hear about the next one before it lands here.
                    </p>
                    <a href="{{ route('join') }}"
                       data-testid="event-related-join"
                       class="mt-4 inline-flex items-center justify-center gap-2 min-h-11 px-6 rounded-md
                              bg-brand text-on-brand font-semibold
                              hover:bg-brand-hover active:bg-brand-active
                              transition-colors duration-fast ease-out-quick">
                        Join the Discord
                    </a>
                @endguest
            </section>
        @endif
    </div>
</x-layouts.app>
