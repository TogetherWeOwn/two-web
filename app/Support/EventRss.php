<?php

namespace App\Support;

use App\Enums\EventStatus;
use App\Models\Event;

/**
 * The event collection as an RSS 2.0 feed.
 *
 * A pure builder over already-fetched `Event` models — no query, no auth, no
 * HTTP. The controller owns the visibility rule (publicly viewable events only)
 * and the `application/rss+xml` response; this owns the text, so a change to
 * either side has one place to land.
 *
 * `pubDate` carries the start instant in UTC, not the wall time: feed readers
 * sort on it, and a floating local time would reintroduce the DST ambiguity the
 * `timezone` column exists to kill.
 */
final class EventRss
{
    /**
     * Takes any iterable of `Event` models — including the Eloquent collection
     * `->get()` returns, which is *not* an `Illuminate\Support\Collection`.
     *
     * @param  iterable<int, Event>  $events
     */
    public static function for(iterable $events): string
    {
        $items = '';

        foreach ($events as $event) {
            $items .= self::item($event);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0">'
            .'<channel>'
            .'<title>'.self::e((string) config('app.name').' Events').'</title>'
            .'<link>'.self::e(route('events.index')).'</link>'
            .'<description>'.self::e('Upcoming events from '.config('app.name')).'</description>'
            .'<lastBuildDate>'.now('UTC')->format(DATE_RSS).'</lastBuildDate>'
            .$items
            .'</channel>'
            .'</rss>';
    }

    private static function item(Event $event): string
    {
        $url = route('events.page', $event);

        // A cancelled event stays in the feed — readers that already picked it
        // up need the update — but the title says so, or the reader shows it
        // as if it were still on.
        $title = $event->status === EventStatus::Cancelled
            ? '[Cancelled] '.$event->title
            : $event->title;

        $xml = '<item>'
            .'<title>'.self::e($title).'</title>'
            .'<link>'.self::e($url).'</link>'
            // The permalink is the guid: re-fetching the feed updates the entry
            // instead of duplicating it.
            .'<guid isPermaLink="true">'.self::e($url).'</guid>'
            .'<pubDate>'.$event->starts_at->setTimezone('UTC')->format(DATE_RSS).'</pubDate>';

        if (is_string($event->description) && $event->description !== '') {
            $xml .= '<description>'.self::e($event->description).'</description>';
        }

        return $xml.'</item>';
    }

    /** XML-escape text content: `&`, `<`, `>` and both quote styles. */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
