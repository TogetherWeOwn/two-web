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
 * `SEQUENCE` is a persisted, database-owned revision counter, so even same-second
 * edits advance it and synced calendar clients apply the latest content.
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
            'DTSTAMP:'.self::dtstamp($event),
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
     * The content clock behind `DTSTAMP`: the moment the row last changed, not
     * the moment it is rendered. RFC 5545 wants the entry's creation or last
     * revision instant here; stamping `now()` instead made every render unique
     * bytes, so no validator could ever match and calendar clients re-downloaded
     * the full body on every poll. `updated_at` records the actual write time;
     * same-second edits are distinguished by `SEQUENCE`, not a fabricated future
     * timestamp. Unchanged content renders byte-identical bodies. The `?? 0`
     * fallback (epoch) only fires for an
     * unsaved model, which both controllers can never serve.
     */
    private static function dtstamp(Event $event): string
    {
        return gmdate('Ymd\THis\Z', $event->updated_at?->getTimestamp() ?? 0);
    }

    /**
     * RFC 5545 §3.8.7.4 revision counter. The database advances it atomically,
     * even when updated_at repeats or goes backwards and model hooks are bypassed.
     * Seeded from the legacy Unix timestamp so existing clients never see a reset.
     */
    private static function sequence(Event $event): int
    {
        // Keep the legacy fallback for transient models without a persisted counter.
        return $event->ics_sequence ?? $event->updated_at?->getTimestamp() ?? 0;
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
