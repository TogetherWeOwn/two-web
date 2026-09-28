<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Support\EventIcs;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The event collection as a subscribable calendar: `GET /events.ics`.
 *
 * The per-event download (`/events/{event_key}.ics`, via `EventIcsController`)
 * is a one-shot file; this is the URL a calendar client polls, so it carries
 * every upcoming event as one `VCALENDAR`. Same `.suffix` trick as the `.rss`
 * feed: one URL, one media type, public like the page because a calendar
 * client has no session.
 *
 * Scope is published upcoming plus cancelled upcoming — not drafts, never
 * per-user. A cancelled event stays in the feed as `STATUS:CANCELLED` so a
 * client that already synced it retracts the entry instead of keeping a stale
 * one; the feed reader is always a guest, so moderators see the same body.
 * "Upcoming" is the calendar's definition (`ends_at >= now`), same as RSS.
 */
final class EventFeedController
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Event::class);

        $events = Event::query()
            ->whereIn('status', [EventStatus::Published->value, EventStatus::Cancelled->value])
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get();

        $response = response(EventIcs::collection($events), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            // `inline`, not `attachment`: this URL is for subscribing, not for
            // saving a file, and a plain click should not force a download
            // dialog before the member can copy the link or follow webcal.
            'Content-Disposition' => 'inline; filename="events.ics"',
            'Cache-Control' => 'public, max-age=300',
        ]);

        // Same strong-validator-over-bytes pattern as the RSS feed (`TOG-7330`):
        // `DTSTAMP` rides the content clock (`EventIcs` stamps the row's
        // `updated_at`), so unchanged content is byte-identical and a repeat
        // poll with `If-None-Match` answers 304 with no body.
        $content = $response->getContent();
        $response->setEtag(hash('sha256', $content === false ? '' : $content));
        $response->isNotModified($request);

        return $response;
    }
}
