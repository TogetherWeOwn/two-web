{{--
    The events calendar, built to the Designer's spec (COMPONENTS.md §5, §8, §9;
    COPY.md). Two things about this markup are load-bearing rather than cosmetic:

      * The copy is quoted from COPY.md verbatim. "Nothing on the calendar yet"
        is not a placeholder to be tidied into "No events" — the empty state has
        to read as *early*, and the exact framing ("the Discord is where it
        happens first") is what turns an empty calendar into a reason to join.

      * `data-testid` attributes are QA's contract for the Dusk round trip.
        Renaming one does not fail a test; it silently makes the browser suite
        stop exercising the thing it names.

    Mobile first throughout: this is opened in the Discord in-app browser on a
    phone more than anywhere else, so the single-column layout is the base case
    and the grid is the enhancement.
--}}
<div data-testid="events-calendar" class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 sm:py-12">
    <header class="mb-6 sm:mb-8">
        <h1 class="u-display text-3xl text-ink sm:text-4xl">Events</h1>
        <p class="mt-2 max-w-prose text-sm text-ink-muted">
            Game nights, raids and whatever else the guild is running.
        </p>
    </header>

    @auth
        {{-- The RSVP is a write, so it needs a live region: a member who cannot
             see the button change still has to be told what happened. --}}
        <p class="sr-only" role="status" aria-live="polite" wire:loading.class.remove="sr-only" wire:target="rsvp,withdraw">
            Saving your RSVP…
        </p>
    @endauth

    @if ($upcoming->isEmpty())
        @if ($past->isEmpty())
            {{-- Nothing has ever been scheduled. COMPONENTS.md §8, first variant. --}}
            <div class="u-hatch rounded-lg border border-line p-8 text-center" data-testid="events-empty">
                <h2 class="text-lg font-semibold text-ink">Nothing on the calendar yet.</h2>
                <p class="mx-auto mt-1.5 max-w-prose text-sm text-ink-muted">
                    Game nights get posted here first. Join the Discord and you will see them
                    before they land on this page.
                </p>
                <div class="mt-5">
                    <a href="{{ route('discord') }}"
                       data-testid="events-empty-action"
                       class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-brand px-6 font-semibold text-on-brand transition-colors duration-fast ease-out-quick hover:bg-brand-hover active:bg-brand-active">
                        Join the Discord
                    </a>
                </div>
            </div>
        @else
            {{-- There is a past. Saying so is the difference between "quiet week"
                 and "nobody plays here". COMPONENTS.md §8, second variant. --}}
            <div class="u-hatch rounded-lg border border-line p-8 text-center" data-testid="events-empty">
                <h2 class="text-lg font-semibold text-ink">Nothing scheduled right now.</h2>
                <p class="mx-auto mt-1.5 max-w-prose text-sm text-ink-muted">
                    The last one was {{ $past->first()->startsAtLocal()->diffForHumans() }}.
                    They usually go up about a week ahead.
                </p>
                <div class="mt-5">
                    <a href="#past-events"
                       data-testid="events-empty-action"
                       class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-line-strong bg-transparent px-5 text-ink transition-colors duration-fast ease-out-quick hover:border-ink-muted hover:bg-raised active:bg-surface">
                        See past events
                    </a>
                </div>
            </div>
        @endif
    @else
        <ul role="list" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @foreach ($upcoming as $event)
                @php
                    $rsvp = $mine->get($event->id);
                    $isGoing = $rsvp?->status === \App\Enums\RsvpStatus::Going;
                    // Capacity is only a wall for an answer that newly takes a
                    // seat, so a member already going is never "full".
                    $isFull = $event->capacity !== null
                        && $event->going_count >= $event->capacity
                        && ! $isGoing;
                    $failure = $failures[$event->event_key] ?? null;
                @endphp

                <li>
                    <article data-testid="event-card"
                             class="flex h-full flex-col rounded-lg border border-line bg-surface p-5">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="text-lg font-semibold text-ink">{{ $event->title }}</h3>

                            @if ($event->status === \App\Enums\EventStatus::Draft)
                                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-sm border border-line bg-raised px-2 py-0.5 text-xs font-medium text-ink-muted">
                                    Draft
                                </span>
                            @elseif ($isFull)
                                <span data-testid="event-full"
                                      class="inline-flex shrink-0 items-center gap-1.5 rounded-sm bg-alert-quiet px-2 py-0.5 text-xs font-medium text-alert">
                                    This one's full
                                </span>
                            @endif
                        </div>

                        @if ($event->game)
                            <p class="mt-1 text-sm text-ink-muted">{{ $event->game }}</p>
                        @endif

                        {{-- Both readings of the time: the machine one for
                             assistive tech and the wall time the host meant. --}}
                        <p class="mt-3 text-sm text-ink">
                            <time datetime="{{ $event->starts_at->toIso8601String() }}" class="u-numeric">
                                {{ $event->startsAtLocal()->format('D j M') }}
                                ·
                                {{ $event->startsAtLocal()->format('H:i') }}
                            </time>
                            <span class="text-ink-muted">{{ $event->startsAtLocal()->format('T') }}</span>
                        </p>

                        @if ($event->location)
                            <p class="mt-1 text-sm text-ink-muted">{{ $event->location }}</p>
                        @endif

                        @if ($event->capacity !== null)
                            <p class="mt-1 text-sm text-ink-muted u-numeric">
                                {{ $event->going_count }} / {{ $event->capacity }} going
                            </p>
                        @endif

                        <div class="mt-auto pt-5">
                            @guest
                                <a href="{{ route('login') }}"
                                   class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md border border-line-strong bg-transparent px-5 text-ink transition-colors duration-fast ease-out-quick hover:border-ink-muted hover:bg-raised">
                                    Sign in with Discord
                                </a>
                            @else
                                @if ($isGoing)
                                    <div class="flex flex-wrap items-center gap-3">
                                        <p class="inline-flex items-center gap-1.5 text-sm font-medium text-ink">
                                            {{-- Icon and words, never colour alone. --}}
                                            <svg class="size-4 shrink-0 text-online" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                                <path d="M3.5 8.5l3 3 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                            You're in
                                        </p>

                                        <button type="button"
                                                wire:click="withdraw('{{ $event->event_key }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="withdraw('{{ $event->event_key }}')"
                                                data-testid="rsvp-withdraw"
                                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md px-3 text-ink-muted transition-colors duration-fast ease-out-quick hover:bg-raised hover:text-ink">
                                            <span wire:loading.remove wire:target="withdraw('{{ $event->event_key }}')">Can't make it</span>
                                            <span wire:loading wire:target="withdraw('{{ $event->event_key }}')" aria-busy="true">Saving…</span>
                                        </button>
                                    </div>

                                    @if ($rsvp && $rsvp->synced_to_discord_at === null)
                                        {{-- Saved here, not yet in Discord. This is a
                                             true statement about a successful RSVP, not
                                             an error — see the component docblock. --}}
                                        <p data-testid="rsvp-syncing" class="mt-2 text-xs text-ink-muted">
                                            Syncing to Discord — your seat is saved either way.
                                        </p>
                                    @endif
                                @elseif ($isFull)
                                    <button type="button"
                                            disabled
                                            data-testid="rsvp-button-{{ $event->event_key }}"
                                            class="inline-flex min-h-11 cursor-not-allowed items-center justify-center gap-2 rounded-md border border-line bg-surface px-6 font-semibold text-ink-disabled">
                                        This one's full
                                    </button>
                                @else
                                    <button type="button"
                                            wire:click="rsvp('{{ $event->event_key }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="rsvp('{{ $event->event_key }}')"
                                            data-testid="rsvp-button-{{ $event->event_key }}"
                                            class="inline-flex min-h-11 items-center justify-center gap-2 rounded-md bg-brand px-6 font-semibold text-on-brand transition-colors duration-fast ease-out-quick hover:bg-brand-hover active:bg-brand-active">
                                        {{-- The box does not change size between
                                             states: the spinner takes the icon slot
                                             the default state already reserves. --}}
                                        <svg wire:loading wire:target="rsvp('{{ $event->event_key }}')"
                                             class="size-4 shrink-0 animate-spin" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                            <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2" opacity="0.25"/>
                                            <path d="M14 8a6 6 0 00-6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                        </svg>
                                        <span wire:loading.remove wire:target="rsvp('{{ $event->event_key }}')">I'm in</span>
                                        <span wire:loading wire:target="rsvp('{{ $event->event_key }}')" aria-busy="true">Saving…</span>
                                    </button>
                                @endif

                                @if ($failure === 'unsaved')
                                    {{-- The only message that says an RSVP did not
                                         work, and it is only reachable from a real
                                         write failure. --}}
                                    <p data-testid="rsvp-error" role="status"
                                       class="mt-2 flex items-start gap-1.5 text-sm text-alert">
                                        <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                                            <path d="M8 5.5v3.5M8 11.5h.01M7.1 2.6L1.5 12a1 1 0 00.9 1.5h11.2a1 1 0 00.9-1.5L8.9 2.6a1 1 0 00-1.8 0z"
                                                  stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                        <span>That RSVP didn't save. Try once more.</span>
                                    </p>
                                @elseif ($failure === 'full')
                                    <p data-testid="rsvp-full-notice" role="status" class="mt-2 text-sm text-alert">
                                        This one's full. Cap is {{ $event->capacity }}.
                                    </p>
                                @elseif ($failure === 'closed')
                                    <p data-testid="rsvp-closed-notice" role="status" class="mt-2 text-sm text-ink-muted">
                                        This event isn't taking RSVPs any more.
                                    </p>
                                @endif
                            @endguest
                        </div>
                    </article>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($past->isNotEmpty())
        <section id="past-events" class="mt-10 sm:mt-12">
            <h2 class="u-display text-xl text-ink">Past events</h2>
            <ul role="list" class="mt-4 flex flex-col gap-3">
                @foreach ($past as $event)
                    <li>
                        <article data-testid="past-event-card"
                                 class="rounded-lg border border-line bg-surface p-4">
                            <h3 class="text-base font-medium text-ink">{{ $event->title }}</h3>
                            <p class="mt-1 text-sm text-ink-muted">
                                <time datetime="{{ $event->starts_at->toIso8601String() }}" class="u-numeric">
                                    {{ $event->startsAtLocal()->format('D j M Y') }}
                                </time>
                            </p>
                        </article>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
