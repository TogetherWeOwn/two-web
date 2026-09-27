<?php

namespace App\Support;

use App\Enums\EventStatus;
use App\Models\Event;

/**
 * One event as an RFC 5545 `VCALENDAR` download.
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
 */
final class EventIcs
{
    public static function for(Event $event): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TogetherWeOwn//Events//EN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.self::uid($event),
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

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // CRLF, not PHP_EOL: RFC 5545 §3.1 names the line break, and a download
        // built on a Linux box is opened on whatever the member uses.
        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
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
