<?php

namespace App\Support;

use App\Models\Event;
use Carbon\CarbonImmutable;

/**
 * One event as a Google Calendar one-click add link.
 *
 * A pure builder over the same `Event` model the JSON API, the ICS download
 * and the shareable page read — no query, no auth, no HTTP. The controller
 * owns the visibility rule (the `view` policy); this owns the URL, so a change
 * to either side has one place to land.
 *
 * Dates go out as UTC instants (`YYYYMMDDTHHMMSSZ`), the same contract as the
 * ICS download: the model stores the instant and the zone separately, and a
 * floating local time would reintroduce the DST ambiguity the `timezone`
 * column exists to kill. Google interprets the `Z` suffix as UTC, so no `ctz`
 * parameter is needed.
 */
final class EventGoogleCalendar
{
    public static function url(Event $event): string
    {
        $params = [
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => self::instant($event->starts_at).'/'.self::instant($event->ends_at),
        ];

        if (is_string($event->description) && $event->description !== '') {
            $params['details'] = $event->description;
        }

        if (is_string($event->location) && $event->location !== '') {
            $params['location'] = $event->location;
        }

        // RFC3986, so spaces become `%20` rather than `+`: the template endpoint
        // reads both, but `%20` survives a second round of encoding (a member
        // forwarding the link) where a literal `+` would turn into a space.
        return 'https://calendar.google.com/calendar/render?'
            .http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private static function instant(CarbonImmutable $at): string
    {
        return $at->setTimezone('UTC')->format('Ymd\THis\Z');
    }
}
