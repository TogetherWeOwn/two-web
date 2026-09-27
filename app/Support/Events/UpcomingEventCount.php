<?php

namespace App\Support\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use Throwable;

/**
 * How many published upcoming events the join page can point at.
 *
 * Never throws, like the landing page's counts reader: the join page is the
 * funnel floor beside `/discord`, and a database hiccup must not take the
 * pitch down with it. Null means "we do not know" — the view omits the events
 * line rather than printing a zero that would read as "nothing is ever on".
 *
 * "Upcoming" is the calendar's definition (`ends_at >= now`, including an
 * event happening right now) restricted to published rows — the same predicate
 * as the public RSS feed. A guest cannot see drafts, so the count must not
 * include them either.
 */
final class UpcomingEventCount
{
    /** The upcoming-event count, or null when it could not be read. */
    public function count(): ?int
    {
        try {
            return Event::query()
                ->where('status', EventStatus::Published->value)
                ->where('ends_at', '>=', now())
                ->count();
        } catch (Throwable) {
            return null;
        }
    }
}
