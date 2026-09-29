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

        // TOG-8706: the canonical names the archive page being viewed. A
        // crawler fetching `?page=2` must index it under that address, not
        // under page 1 — otherwise every archive page competes with the
        // first. Page 1 keeps the bare route: `?page=1` is the same page
        // with noise appended.
        //
        // TOG-9012: an out-of-range page (`?page=999` past `lastPage`) is a
        // 200 soft-404 rendering the TOG-7989 "doesn't exist" state. Letting
        // it self-canonicalize makes every `?page=N` a distinct indexable
        // page of miss content — unbounded crawl space. Point it at the bare
        // archive URL instead, the same as page 1.
        $page = $events->currentPage();
        $outOfRange = $events->isEmpty() && $events->total() > 0;

        return view('livewire.past-events', [
            'events' => $events,
            // Share tags (TOG-5624). Same contract as the calendar: `layoutData`
            // merges into the `#[Layout]` params, and `route()` builds from
            // APP_URL, never a hardcoded hostname.
        ])->layoutData([
            'canonical' => ($page > 1 && ! $outOfRange)
                ? route('events.past', ['page' => $page])
                : route('events.past'),
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
