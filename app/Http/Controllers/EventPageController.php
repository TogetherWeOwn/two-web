<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The shareable event page: one event as HTML for a link passed around Discord.
 *
 * This is deliberately not `EventController::show`. That route (`/events/{event}`,
 * inside the `auth` group) is JSON, and one URL must not serve two media types —
 * content-negotiating on the Accept header gives crawlers and curl different
 * answers. So this lives at `/e/{event_key}` and stays HTML unconditionally.
 *
 * The page is public — a signed-out visitor arriving from Discord must land on
 * the event, not on the OAuth handoff — and the visibility rule is the same
 * policy the JSON route enforces: published (and cancelled/past) events for
 * everyone, drafts for moderators only.
 *
 * A cancelled event answers 410 Gone, not 200 and not 404: the URL stays up so
 * shares and crawlers can tell "called off" apart from "never existed". A draft
 * carries `noindex` so a moderator's preview URL never enters the index.
 */
final class EventPageController
{
    public function __invoke(Event $event): Response
    {
        Gate::authorize('view', $event);

        // Gone, not missing. A 404 would tell a crawler the link was wrong; the
        // link was right and the event is off, and the body says so in words.
        if ($event->status === EventStatus::Cancelled) {
            return response()->view('events.gone', ['event' => $event], 410);
        }

        // The going count the page prints, and the viewer's own answer the RSVP
        // control reads: one query here rather than one per component.
        $event->loadCount(['rsvps as going_count' => fn ($query) => $query->where('status', RsvpStatus::Going)]);
        $event->loadMissing('viewerRsvps');

        // The lifetime view total for the moderator badge (TOG-8408): one SUM
        // over the narrow daily rows, next to the going_count aggregate above
        // rather than a query per render. Guests never see it — see the blade.
        $event->loadSum('viewCounts as view_count', 'views');

        // Who's going: member display names for signed-in viewers only, one
        // query ordered by answer time. A guest gets the count the page already
        // prints plus the join pitch — no member-identifying data leaves the
        // server for a logged-out visitor (TOG-5621). Names only, no profile
        // links: those belong to TOG-6926.
        $attendees = auth()->check()
            ? $event->rsvps()
                ->where('status', RsvpStatus::Going)
                ->with('user:id,display_name,username')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($rsvp) => $rsvp->user->display_name ?? $rsvp->user?->username)
                ->filter()
                ->values()
            : collect();

        $response = response()->view('events.show', [
            'event' => $event,
            'attendees' => $attendees,
            'previousEvent' => self::neighbor($event, 'previous'),
            'nextEvent' => self::neighbor($event, 'next'),
            'relatedEvents' => self::relatedEvents($event),
        ]);

        // Moderator-only preview: keep it out of the index. Published pages send
        // no robots signal at all — see EventGoneTest.
        if ($event->status === EventStatus::Draft) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /**
     * The adjacent event in `starts_at` order, for prev/next navigation.
     *
     * The same `starts_at, id` ordering as the JSON listing (`EventController::index`):
     * the `id` tiebreak keeps a double-header from pointing at itself. Only events
     * the viewer could open count — drafts for moderators, everything else for
     * everyone — so a guest's "next" never links to a draft that answers 403.
     * Cancelled events are skipped: they answer 410, not a page to browse to.
     */
    private static function neighbor(Event $event, string $direction): ?Event
    {
        $previous = $direction === 'previous';

        return Event::query()
            ->select(['id', 'event_key', 'title', 'starts_at'])
            ->unless(
                Gate::allows('viewDrafts', Event::class),
                fn (Builder $query): Builder => $query->where('status', '!=', EventStatus::Draft->value),
            )
            ->where('status', '!=', EventStatus::Cancelled->value)
            ->where(
                fn (Builder $query): Builder => $previous
                    ? $query->where('starts_at', '<', $event->starts_at)
                        ->orWhere(fn (Builder $nested): Builder => $nested
                            ->where('starts_at', $event->starts_at)
                            ->where('id', '<', $event->id))
                    : $query->where('starts_at', '>', $event->starts_at)
                        ->orWhere(fn (Builder $nested): Builder => $nested
                            ->where('starts_at', $event->starts_at)
                            ->where('id', '>', $event->id)),
            )
            ->when($previous,
                fn (Builder $query): Builder => $query->orderByDesc('starts_at')->orderByDesc('id'),
                fn (Builder $query): Builder => $query->orderBy('starts_at')->orderBy('id'),
            )
            ->first();
    }

    /**
     * Up to 3 sibling events for the related-events block, same game first.
     *
     * There is no `series` column — `game` is the series ("Helldivers 2"
     * nights are a series the way the calendar treats them). Same-game
     * upcoming events come first, then the nearest other upcoming events to
     * fill up to 3, so the block is still useful for a one-off game. The
     * current event, cancelled events (410, not a page to browse to) and —
     * for guests — drafts are excluded, the same visibility rule as
     * `neighbor()`. One query when there is no game or the same-game rows
     * fill the block, two at most.
     *
     * @return Collection<int, Event>
     */
    private static function relatedEvents(Event $event): Collection
    {
        $upcoming = fn (): Builder => Event::query()
            ->select(['id', 'event_key', 'title', 'starts_at', 'timezone', 'location'])
            ->unless(
                Gate::allows('viewDrafts', Event::class),
                fn (Builder $query): Builder => $query->where('status', '!=', EventStatus::Draft->value),
            )
            ->where('status', '!=', EventStatus::Cancelled->value)
            ->where('id', '!=', $event->id)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->orderBy('id');

        $related = $event->game !== null
            ? $upcoming()->where('game', $event->game)->limit(3)->get()
            : collect();

        if ($related->count() < 3) {
            $more = $upcoming()
                ->when(
                    $related->isNotEmpty(),
                    fn (Builder $query): Builder => $query->whereNotIn('id', $related->pluck('id')->all()),
                )
                ->limit(3 - $related->count())
                ->get();

            $related = $related->concat($more);
        }

        return $related->values();
    }
}
