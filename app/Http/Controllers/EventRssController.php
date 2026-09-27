<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Support\EventRss;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The event collection as an RSS 2.0 feed: `GET /events.rss`.
 *
 * This is deliberately not content negotiation on `/events`. That path serves
 * the HTML calendar, and one URL answering with two media types is how you end
 * up with a crawler and a browser seeing different sites. The `.rss` suffix
 * keeps the feed on the same public path family while staying unconditionally
 * `application/rss+xml`.
 *
 * Public, like the shareable page: a feed reader has no session, so a login
 * wall would make the feed useless. Only published upcoming events appear —
 * the reader is always a guest, and `viewDrafts` is never granted to one, so
 * per-user visibility cannot apply and the guest rule is the only honest one.
 * "Upcoming" is the calendar's definition (`ends_at >= now`, including an
 * event happening right now), not just "not a draft": a feed of upcoming
 * events that still lists last year's is a bug, not an archive.
 */
final class EventRssController
{
    public function __invoke(): Response
    {
        Gate::authorize('viewAny', Event::class);

        $events = Event::query()
            ->where('status', EventStatus::Published->value)
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get();

        return response(EventRss::for($events), 200, [
            'Content-Type' => 'application/rss+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
