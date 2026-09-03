{{--
    The events calendar and the RSVP flow (TOG-53).

    Built mobile-first from 360px, because the commonest way this page is opened is
    a phone inside the Discord in-app browser. The list is the default view and the
    only one below `sm`: a seven-column month grid at 360px gives each day about
    44px, which is one tap target wide and cannot hold an event title. The grid is
    offered from `sm` up, where it has the room to be useful.

    Every colour, size and radius here is a token from resources/css/two.css. No hex,
    no arbitrary values — see the rules at the top of that file.
--}}
<div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 sm:py-12">
    <header class="mb-8">
        <h1 class="u-display text-3xl sm:text-4xl">Events</h1>
        <p class="mt-2 max-w-prose text-ink-muted">
            What we are playing, and when. Answer here and it shows up in Discord.
        </p>
    </header>

    {{--
        View switch. A real radio group rather than two buttons, because that is
        what it is: one of two, exactly one selected. Screen readers get told which
        without having to infer it from styling.
    --}}
    <div
        class="mb-6 hidden sm:flex sm:items-center sm:gap-2"
        role="radiogroup"
        aria-label="How to show the events"
    >
        <button
            type="button"
            role="radio"
            aria-checked="{{ $mode === 'list' ? 'true' : 'false' }}"
            wire:click="showList"
            data-testid="view-list"
            @class([
                'u-tap rounded-md border px-3 py-2 text-sm transition-colors duration-fast',
                'border-line-strong bg-raised text-ink' => $mode === 'list',
                'border-line text-ink-muted hover:text-ink' => $mode !== 'list',
            ])
        >
            List
        </button>
        <button
            type="button"
            role="radio"
            aria-checked="{{ $mode === 'calendar' ? 'true' : 'false' }}"
            wire:click="showCalendar"
            data-testid="view-calendar"
            @class([
                'u-tap rounded-md border px-3 py-2 text-sm transition-colors duration-fast',
                'border-line-strong bg-raised text-ink' => $mode === 'calendar',
                'border-line text-ink-muted hover:text-ink' => $mode !== 'calendar',
            ])
        >
            Calendar
        </button>
    </div>

    @if ($events->isEmpty())
        {{--
            The empty state. The card is explicit that this must not read as a
            broken page, so it says what is true — there is a next one and it is
            being planned — and offers the one action that helps, which is being in
            the Discord where it gets planned.

            u-hatch is the "nothing here on purpose" texture. Budget is one per
            screen and this is it.
        --}}
        <div
            class="u-hatch rounded-lg border border-line p-8 text-center sm:p-12"
            data-testid="events-empty"
        >
            <div class="mx-auto max-w-md rounded-md bg-canvas p-6">
                <h2 class="u-display text-2xl">The next one is being planned</h2>
                <p class="mt-3 text-ink-muted">
                    Nothing is on the calendar this minute. Nights get picked in Discord,
                    usually a week or so ahead — that is where you will see the next one
                    first.
                </p>
                <a
                    href="{{ route('discord') }}"
                    class="u-tap mt-6 inline-block rounded-md bg-brand px-4 py-2 text-on-brand transition-colors duration-fast hover:bg-brand-hover"
                    data-testid="events-empty-join"
                >
                    Join the Discord
                </a>
            </div>
        </div>
    @elseif ($mode === 'calendar')
        {{-- Month grid. Hidden below sm, where the list is the only sensible view. --}}
        <div class="hidden sm:block" data-testid="events-calendar">
            <div class="mb-4 flex items-center justify-between gap-4">
                <button
                    type="button"
                    wire:click="previousMonth"
                    class="u-tap rounded-md border border-line px-3 py-2 text-sm text-ink-muted transition-colors duration-fast hover:text-ink"
                    data-testid="calendar-previous"
                >
                    <span aria-hidden="true">&larr;</span>
                    <span class="sr-only">Previous month</span>
                </button>

                <h2 class="u-numeric text-xl" aria-live="polite" data-testid="calendar-month">
                    {{ $monthLabel }}
                </h2>

                <button
                    type="button"
                    wire:click="nextMonth"
                    class="u-tap rounded-md border border-line px-3 py-2 text-sm text-ink-muted transition-colors duration-fast hover:text-ink"
                    data-testid="calendar-next"
                >
                    <span aria-hidden="true">&rarr;</span>
                    <span class="sr-only">Next month</span>
                </button>
            </div>

            {{--
                A real <table>. A month grid is tabular data — a date is the
                intersection of a weekday and a week — and a grid of <div>s makes a
                screen reader read 42 unlabelled cells in a row.
            --}}
            <table class="w-full table-fixed border-collapse">
                <caption class="sr-only">Events in {{ $monthLabel }}</caption>
                <thead>
                    <tr>
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                            <th scope="col" class="p-2 text-xs font-normal text-ink-muted">
                                <span aria-hidden="true">{{ substr($weekday, 0, 1) }}</span>
                                <span class="sr-only">{{ $weekday }}</span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($weeks as $week)
                        <tr>
                            @foreach ($week as $day)
                                <td
                                    @class([
                                        'h-24 border border-line p-1 align-top',
                                        'bg-surface' => $day['inMonth'],
                                        'text-ink-disabled' => ! $day['inMonth'],
                                        'outline outline-line-strong' => $day['isToday'],
                                    ])
                                    @if ($day['isToday']) aria-current="date" @endif
                                >
                                    <span class="u-numeric block text-xs {{ $day['inMonth'] ? 'text-ink-muted' : '' }}">
                                        {{ $day['date']->format('j') }}
                                    </span>

                                    @foreach ($day['events'] as $event)
                                        <a
                                            href="#event-{{ $event->event_key }}"
                                            @class([
                                                'mt-1 block truncate rounded-sm px-1 py-0.5 text-xs',
                                                'bg-brand-quiet text-brand-ink' => $event->status !== \App\Enums\EventStatus::Cancelled,
                                                'bg-alert-quiet text-ink-muted line-through' => $event->status === \App\Enums\EventStatus::Cancelled,
                                            ])
                                            data-testid="calendar-event-{{ $event->event_key }}"
                                        >
                                            <span class="u-numeric">{{ $event->startsAtLocal()->format('H:i') }}</span>
                                            {{ $event->title }}
                                        </a>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Below sm the grid is not rendered, so the list is what is there. --}}
        <ul class="space-y-4 sm:hidden" role="list" data-testid="events-list">
            @foreach ($events as $event)
                @include('livewire.partials.event-card', ['event' => $event])
            @endforeach
        </ul>
    @else
        <ul class="space-y-4" role="list" data-testid="events-list">
            @foreach ($events as $event)
                @include('livewire.partials.event-card', ['event' => $event])
            @endforeach
        </ul>
    @endif
</div>
