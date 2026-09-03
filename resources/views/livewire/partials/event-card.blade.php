{{--
    One event, and every state a member can find it in.

    Included from both views, so a state cannot be handled in the list and missing
    from the calendar. `$this` is the EventsCalendar component — the derived
    questions (is it full *for me*, what did I answer, how many seats are left) are
    methods on it rather than logic here, because they are the kind of thing that
    goes subtly wrong in two places when it lives in a template.

    Testids are on every state QA's round-trip journey has to find. They are part of
    the contract with that journey, not decoration: renaming one breaks the Dusk
    test, which is the intended behaviour.
--}}
@php
    $answer = $this->answerFor($event);
    $isCancelled = $event->status === \App\Enums\EventStatus::Cancelled;
    $isDraft = $event->status === \App\Enums\EventStatus::Draft;
    $isFull = $this->isFullFor($event);
    $remaining = $this->spotsRemaining($event);
    $error = $rsvpErrors[$event->event_key] ?? null;
@endphp

<li
    id="event-{{ $event->event_key }}"
    class="rounded-lg border border-line bg-surface p-4 sm:p-6"
    data-testid="event-{{ $event->event_key }}"
    wire:key="event-{{ $event->event_key }}"
>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-lg text-ink">{{ $event->title }}</h3>

                @if ($isDraft)
                    {{-- Moderators only; EventPolicy keeps this off everyone else's page. --}}
                    <span class="rounded-sm border border-line-strong px-2 py-0.5 text-xs text-ink-muted">
                        Not announced yet
                    </span>
                @endif

                @if ($isCancelled)
                    <span
                        class="rounded-sm bg-alert-quiet px-2 py-0.5 text-xs text-alert"
                        data-testid="event-cancelled-{{ $event->event_key }}"
                    >
                        Cancelled
                    </span>
                @endif

                @if ($isFull && ! $isCancelled)
                    {{--
                        "Full" is not shown to somebody who already holds a seat —
                        see EventsCalendar::isFullFor(). Text as well as colour: the
                        alert token never carries meaning on its own.
                    --}}
                    <span
                        class="rounded-sm bg-alert-quiet px-2 py-0.5 text-xs text-alert"
                        data-testid="event-full-{{ $event->event_key }}"
                    >
                        Full
                    </span>
                @endif
            </div>

            @if ($event->game)
                <p class="mt-1 text-sm text-ink-muted">{{ $event->game }}</p>
            @endif

            {{--
                The machine-readable instant in `datetime`, the local wall time as
                the text. A member reads "Fri 12 Sep, 20:00" and does not have to
                know it is 8pm in London rather than where they are — the zone is
                spelled out next to it.
            --}}
            <p class="u-numeric mt-2 text-sm text-ink">
                <time datetime="{{ $event->starts_at->toIso8601String() }}">
                    {{ $event->startsAtLocal()->format('D j M, H:i') }}
                </time>
                <span class="text-ink-muted">
                    &ndash; {{ $event->endsAtLocal()->format('H:i') }}
                    ({{ $event->startsAtLocal()->format('T') }})
                </span>
            </p>

            @if ($event->location)
                <p class="mt-1 text-sm text-ink-muted">{{ $event->location }}</p>
            @endif

            {{--
                One sentence, built in PHP rather than with an inline @if: a Blade
                directive glued to the end of a word is not a directive (Blade needs
                a non-word character before the @), so "going@if" compiles as
                literal text and leaves its @endif dangling.
            --}}
            <p class="u-numeric mt-2 text-sm text-ink-muted" data-testid="event-going-{{ $event->event_key }}">
                @php
                    $seatLine = $this->goingCount($event).' going';

                    if ($remaining !== null) {
                        $seatLine .= ', '.$remaining.' of '.$event->capacity.' '
                            .\Illuminate\Support\Str::plural('seat', $event->capacity).' left';
                    }
                @endphp
                {{ $seatLine }}
            </p>
        </div>

        {{-- The answer. Everything that can be true about the member's RSVP is here. --}}
        <div class="shrink-0 sm:w-56">
            @if ($isCancelled)
                <p class="text-sm text-ink-muted">This one is off. Nothing to answer.</p>
            @elseif ($isDraft)
                <p class="text-sm text-ink-muted">Publish it before anyone can answer.</p>
            @elseif (! auth()->check())
                <a
                    href="{{ route('login') }}"
                    class="u-tap block rounded-md bg-brand px-4 py-2 text-center text-on-brand transition-colors duration-fast hover:bg-brand-hover"
                    data-testid="rsvp-signin-{{ $event->event_key }}"
                >
                    Sign in to answer
                </a>
            @else
                {{--
                    A radio group, not three toggle buttons: it is one answer out of
                    three, and `aria-checked` is how that gets said out loud. The
                    group is labelled by the event title so a screen reader landing
                    on it knows which event it is answering for.
                --}}
                <div
                    role="radiogroup"
                    aria-label="Your answer to {{ $event->title }}"
                    class="flex gap-2"
                    data-testid="rsvp-state-{{ $event->event_key }}"
                    data-state="{{ $answer?->status->value ?? 'none' }}"
                >
                    {{-- Pairs, not a keyed array: an enum case cannot be an array key. --}}
                    @foreach ([[\App\Enums\RsvpStatus::Going, 'Going'], [\App\Enums\RsvpStatus::Maybe, 'Maybe'], [\App\Enums\RsvpStatus::NotGoing, 'Can\'t']] as [$status, $label])
                        @php
                            $isChosen = $answer?->status === $status;
                            // The only thing capacity closes off is newly taking a
                            // seat. Maybe and Can't stay available on a full event,
                            // and so does the answer you have already given.
                            $isBlocked = $status === \App\Enums\RsvpStatus::Going && $isFull && ! $isChosen;
                        @endphp

                        <button
                            type="button"
                            role="radio"
                            aria-checked="{{ $isChosen ? 'true' : 'false' }}"
                            @disabled($isBlocked)
                            wire:click="rsvp('{{ $event->event_key }}', '{{ $status->value }}')"
                            wire:loading.attr="disabled"
                            wire:target="rsvp('{{ $event->event_key }}', '{{ $status->value }}')"
                            data-testid="rsvp-{{ str_replace('_', '-', $status->value) }}-{{ $event->event_key }}"
                            @class([
                                'u-tap flex-1 rounded-md border px-2 py-2 text-sm transition-colors duration-fast',
                                'border-brand bg-brand text-on-brand' => $isChosen,
                                'border-line-strong text-ink hover:bg-raised' => ! $isChosen && ! $isBlocked,
                                'border-line text-ink-disabled' => $isBlocked,
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                {{--
                    In flight. An honest loading state means it is tied to *this*
                    event's call — wire:target — so answering one event does not put
                    every other card on the page into a spinner.
                --}}
                <p
                    class="mt-2 text-sm text-ink-muted"
                    wire:loading
                    wire:target="rsvp('{{ $event->event_key }}', '{{ \App\Enums\RsvpStatus::Going->value }}'), rsvp('{{ $event->event_key }}', '{{ \App\Enums\RsvpStatus::Maybe->value }}'), rsvp('{{ $event->event_key }}', '{{ \App\Enums\RsvpStatus::NotGoing->value }}'), withdraw('{{ $event->event_key }}')"
                    data-testid="rsvp-saving-{{ $event->event_key }}"
                    role="status"
                >
                    Saving your answer&hellip;
                </p>

                @if ($answer)
                    <button
                        type="button"
                        wire:click="withdraw('{{ $event->event_key }}')"
                        class="u-tap mt-2 text-sm text-ink-muted underline transition-colors duration-fast hover:text-ink"
                        data-testid="rsvp-withdraw-{{ $event->event_key }}"
                    >
                        Withdraw my answer
                    </button>

                    @if ($answer->synced_to_discord_at === null)
                        {{--
                            The bot-is-down state, told honestly.

                            The answer is saved — it is in the database, it counts
                            towards capacity, and the queued write-back retries until
                            Discord has it. Saying "RSVP failed" here would be false
                            and would make the member answer twice. See the class
                            docblock on EventsCalendar for the measurement.
                        --}}
                        <p
                            class="mt-2 text-sm text-ink-muted"
                            data-testid="rsvp-pending-{{ $event->event_key }}"
                        >
                            Saved. Discord has not caught up yet &mdash; it will.
                        </p>
                    @endif
                @endif
            @endif

            @if ($error)
                {{--
                    role="alert" so it is announced when it appears: the member has
                    just pressed a button and the answer to what happened is here,
                    not somewhere they have to go looking.
                --}}
                <p
                    class="mt-2 rounded-md bg-alert-quiet p-2 text-sm text-alert"
                    role="alert"
                    data-testid="rsvp-error-{{ $event->event_key }}"
                >
                    {{ $error }}
                </p>
            @endif
        </div>
    </div>
</li>
