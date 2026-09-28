<?php

namespace App\Support;

/**
 * The one-click calendar subscribe URLs for the event collection.
 *
 * `feedUrl()` is the `https` feed a client polls; `webcalUrl()` is the same
 * URL with the scheme swapped, which is what a member's calendar app opens on
 * a click — `webcal://` is `http(s)` with a different scheme, so both names
 * address the same body and there is exactly one feed to keep honest.
 */
final class EventSubscribe
{
    public static function feedUrl(): string
    {
        return route('events.feed');
    }

    public static function webcalUrl(): string
    {
        return (string) preg_replace('#^https?://#', 'webcal://', self::feedUrl());
    }
}
