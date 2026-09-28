<?php

namespace App\Support;

use App\Enums\EventStatus;
use App\Models\Event;

/**
 * One event as an RFC 5545 `VCALENDAR` download, or the whole upcoming
 * collection as one subscribable `VCALENDAR` (`collection()`).
 *
 * This is a pure builder over the same `Event` model the JSON API and the
 * shareable page read — no query, no auth, no HTTP. The controller owns the
 * visibility rule (the `view` policy) and the `text/calendar` response; this
 * owns the text, so a change to either side has one place to land.
 *
 * Times go out as UTC instants (`...Z`), not wall times with a `TZID`: the
 * model stores the instant and the zone separately, and a floating or zoned
 * time would reintroduce the DST ambiguity the `timezone` column exists to
 * kill. `UID` is the immutable `event_key` plus the app host, so re-downloading
 * the same event updates the calendar entry instead of duplicating it.
 * `SEQUENCE` is the `updated_at` Unix timestamp, so any host edit bumps it and
 * already-synced calendar clients apply the update instead of keeping stale data.
 */
final class EventIcs
{
    public static function for(Event $event): string
    {
        return self::calendar(self::vevent($event));
    }

    /**
     * The collection as one `VCALENDAR` with a `VEVENT` per event — the body
     * behind `GET /events.ics`, which calendar clients poll as a subscription.
     * Same per-event contracts as the single download (UTC instants, stable
     * `UID`s, 30-minute `VALARM`); the controller owns the scope (published
     * plus cancelled upcoming — cancelled included, unlike RSS, so a client
     * that already synced the entry retracts it).
     *
     * Takes any iterable of `Event` models — including the Eloquent collection
     * `->get()` returns, which is *not* an `Illuminate\Support\Collection`.
     *
     * @param  iterable<int, Event>  $events
     */
    public static function collection(iterable $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            array_push($lines, ...self::vevent($event));
        }

        return self::calendar($lines);
    }

    /** Wrap pre-built inner lines in the `VCALENDAR` envelope. */
    /** @param  array<int, string>  $inner */
    private static function calendar(array $inner): string
    {
        $lines = array_merge(
            [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//TogetherWeOwn//Events//EN',
                'METHOD:PUBLISH',
                // Non-standard, but Apple Calendar labels a subscription with
                // the raw URL when it is missing — the name is what the member
                // sees in their calendar list.
                'X-WR-CALNAME:'.self::text((string) config('app.name').' Events'),
                'X-WR-CALDESC:'.self::text('Upcoming events from '.config('app.name')),
            ],
            $inner,
            ['END:VCALENDAR'],
        );

        // CRLF, not PHP_EOL: RFC 5545 §3.1 names the line break, and a download
        // built on a Linux box is opened on whatever the member uses.
        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** One event as `VEVENT` lines, without the `VCALENDAR` envelope. */
    /** @return  array<int, string> */
    private static function vevent(Event $event): array
    {
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.self::uid($event),
            'SEQUENCE:'.self::sequence($event),
            'DTSTAMP:'.now('UTC')->format('Ymd\THis\Z'),
            'DTSTART:'.$event->starts_at->setTimezone('UTC')->format('Ymd\THis\Z'),
            'DTEND:'.$event->ends_at->setTimezone('UTC')->format('Ymd\THis\Z'),
            'SUMMARY:'.self::text($event->title),
            'STATUS:'.($event->status === EventStatus::Cancelled ? 'CANCELLED' : 'CONFIRMED'),
        ];

        if (is_string($event->description) && $event->description !== '') {
            $lines[] = 'DESCRIPTION:'.self::text($event->description);
        }

        if (is_string($event->location) && $event->location !== '') {
            $lines[] = 'LOCATION:'.self::text($event->location);
        }

        // Tap-through to the shareable page: the feed exists to drive RSVPs,
        // and without it the entry is a dead end. Emitted raw, not through
        // `text()`: `URL` is a URI-typed property (RFC 5545 §3.8.4.6), so the
        // colons and slashes are literal and backslash-escaping would corrupt it.
        // Same `route()`-in-builder precedent as `EventRss::item()`.
        $lines[] = 'URL:'.route('events.page', $event);

        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'TRIGGER:-PT30M';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:'.self::text($event->title);
        $lines[] = 'END:VALARM';
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Stable across downloads, unique across events. The key alone would do —
     * a ULID is unique by construction — but calendar clients expect the
     * `local@domain` shape, and the host comes from config so a DNS move
     * (TWO-38, TWO-41) does not bake a hostname into the code.
     */
    private static function uid(Event $event): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $host = 'localhost';
        }

        return $event->event_key.'@'.$host;
    }

    /**
     * RFC 5545 §3.8.7.4 revision counter. A persisted counter would need a
     * migration plus a bump-on-update hook for the same guarantee Eloquent
     * already gives: any `save()` touching the row advances `updated_at`, so
     * its Unix timestamp is a monotonic, no-schema-change sequence. A
     * force-fill back to the create instant would repeat a value, but nothing
     * in the codebase writes `updated_at` by hand.
     */
    private static function sequence(Event $event): int
    {
        // `?? 0`: the RFC 5545 default. Unreachable for persisted rows (Eloquent
        // always stamps `updated_at` on create), but `EventIcs::for()` takes any
        // model and an unsaved one has no timestamp to derive from.
        return $event->updated_at?->getTimestamp() ?? 0;
    }

    /** RFC 5545 §3.3.11 escaping: backslash, semicolon, comma, newlines. */
    private static function text(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\;', $value);
        $value = str_replace(',', '\,', $value);

        return str_replace(["\r\n", "\r", "\n"], '\n', $value);
    }

    /**
     * Fold lines longer than 75 octets (§3.1). `mb_strcut` keeps the cut on a
     * character boundary so a multibyte title is never split mid-character.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = mb_strcut($line, 0, 75, 'UTF-8');
        $rest = substr($line, strlen($folded));

        while ($rest !== '') {
            $chunk = mb_strcut($rest, 0, 74, 'UTF-8');
            $folded .= "\r\n ".$chunk;
            $rest = substr($rest, strlen($chunk));
        }

        return $folded;
    }
}
