<?php

namespace App\Support;

use App\Enums\EventStatus;
use App\Models\Event;

/**
 * One event as schema.org `Event` JSON-LD for the shareable page.
 *
 * A pure builder over the same `Event` model the JSON API, the ICS download
 * and the RSS feed read — no query, no auth, no HTTP. The controller owns the
 * visibility rule (the `view` policy); this owns the array, so a change to
 * either side has one place to land.
 *
 * Times go out as UTC instants (`toIso8601String`), not wall times: the model
 * stores the instant and the zone separately, and a floating local time would
 * reintroduce the DST ambiguity the `timezone` column exists to kill. Same
 * contract as the `<time datetime>` attribute the page already prints.
 */
final class EventJsonLd
{
    /** @return array<string, mixed> */
    public static function for(Event $event): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => $event->title,
            'startDate' => $event->starts_at->toIso8601String(),
            'endDate' => $event->ends_at->toIso8601String(),
            'url' => route('events.page', $event),
            'eventStatus' => $event->status === EventStatus::Cancelled
                ? 'https://schema.org/EventCancelled'
                : 'https://schema.org/EventScheduled',
        ];

        if (is_string($event->description) && $event->description !== '') {
            $data['description'] = $event->description;
        }

        if (is_string($event->location) && $event->location !== '') {
            $data['location'] = [
                '@type' => 'Place',
                'name' => $event->location,
            ];
        }

        return $data;
    }
}
