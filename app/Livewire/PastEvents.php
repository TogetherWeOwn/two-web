<?php

namespace App\Livewire;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The past-events archive: everything already over, most recent first.
 *
 * The calendar answers "what's next"; this answers "what did I miss". It is a
 * separate page rather than a longer inline list because the inline past list
 * on the calendar is capped at twenty with no way to reach older history.
 *
 * Server-rendered in one pass like the calendar — the LCP reasoning there
 * applies here too, so the same deferred-runtime layout is used.
 *
 * The ordering is the JSON listing's (`EventController::index`) flipped:
 * `starts_at` descending with the same `id` tiebreak, so a double-header that
 * starts two events at once cannot drift between pages here either.
 */
#[Layout('components.layouts.app', ['deferLivewire' => true, 'title' => 'Past events — Together We Own'])]
class PastEvents extends Component
{
    use WithPagination;

    /**
     * Past events per page. The same twenty as the `GET /events.json` default:
     * one number for "a page of events" everywhere, so neither surface can
     * grow back into the unbounded query pagination replaced.
     */
    private const PER_PAGE = 20;

    public function render(): View
    {
        $events = $this->visible()
            ->where('ends_at', '<', now())
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.past-events', [
            'events' => $events,
            // Share tags (TOG-5624). Same contract as the calendar: `layoutData`
            // merges into the `#[Layout]` params, and `route()` builds from
            // APP_URL, never a hardcoded hostname.
        ])->layoutData([
            'canonical' => route('events.past'),
            'shareDescription' => 'Game nights and tournaments that already happened, most recent first.',
        ]);
    }

    /** @return Builder<Event> */
    private function visible(): Builder
    {
        return Event::query()
            // The card prints the count on every row, so aggregate it once —
            // same N+1 the calendar's `upcoming()` avoids the same way.
            //
            // No `viewerRsvps` here, deliberately: past cards carry no RSVP
            // control (the partial renders none when `$isPast`), so loading
            // every viewer's answer would be a join that answers nothing.
            ->withCount(['rsvps as going_count' => fn ($query) => $query->where('status', RsvpStatus::Going)])
            // A draft has not been announced to anybody. Moderators see them so
            // they can check a card before publishing it; nobody else knows it
            // exists. Same rule as the calendar.
            ->unless(
                Gate::allows('viewDrafts', Event::class),
                fn (Builder $query): Builder => $query->where('status', '!=', EventStatus::Draft->value),
            );
    }
}
