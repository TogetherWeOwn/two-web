<?php

namespace App\Support;

use App\Models\Event;
use DateTimeInterface;

/**
 * The event collection as an RSS 2.0 feed.
 *
 * A pure builder over already-fetched `Event` models — no query, no auth, no
 * HTTP. The controller owns the scope (published upcoming) and the
 * `application/rss+xml` response; this owns the text, so a change to either
 * side has one place to land.
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
     * `$lastBuildDate` is the moment the feed's *content* last changed, not the
     * moment it is rendered: defaulting to `now()` would stamp a fresh instant
     * into every body, so no two responses would ever share bytes and no ETag
     * could survive a second. The controller passes the newest `updated_at` in
     * its scope. An empty feed without a content timestamp omits the optional
     * `lastBuildDate`, keeping its bytes stable as the clock advances. Callers
     * building a nonempty feed without a timestamp still get "just now".
     *
     * @param  iterable<int, Event>  $events
     */
    public static function for(iterable $events, ?DateTimeInterface $lastBuildDate = null): string
    {
        $items = '';

        foreach ($events as $event) {
            $items .= self::item($event);
        }

        $built = $lastBuildDate ?? ($items === '' ? null : now('UTC'));

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">'
            .'<channel>'
            .'<title>'.self::e((string) config('app.name').' Events').'</title>'
            .'<link>'.self::e(route('events.index')).'</link>'
            // The feed's own URL, so a reader holding a copy can confirm the
            // canonical address (TOG-7939): without it the feed is not
            // self-identifying and autodiscovery only works from our pages.
            .'<atom:link href="'.self::e(route('events.rss')).'" rel="self" type="application/rss+xml" />'
            .'<description>'.self::e('Upcoming events from '.config('app.name')).'</description>'
            .($built === null ? '' : '<lastBuildDate>'.$built->format(DATE_RSS).'</lastBuildDate>')
            .$items
            .'</channel>'
            .'</rss>';
    }

    private static function item(Event $event): string
    {
        $url = route('events.page', $event);

        $xml = '<item>'
            .'<title>'.self::e($event->title).'</title>'
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
