<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The shareable event page: one event as HTML for a link passed around Discord.
 *
 * This is deliberately not `EventController::show`. That route (`/events/{event}`,
 * inside the `auth` group) is JSON, and one URL must not serve two media types —
 * content-negotiating on the Accept header gives crawlers and curl different
 * answers. So this lives at `/e/{event_key}` and stays HTML unconditionally.
 *
 * The page is public — a signed-out visitor arriving from Discord must land on
 * the event, not on the OAuth handoff — and the visibility rule is the same
 * policy the JSON route enforces: published (and cancelled/past) events for
 * everyone, drafts for moderators only.
 *
 * A cancelled event answers 410 Gone, not 200 and not 404: the URL stays up so
 * shares and crawlers can tell "called off" apart from "never existed". A draft
 * carries `noindex` so a moderator's preview URL never enters the index.
 */
final class EventPageController
{
    public function __invoke(Event $event): Response
    {
        Gate::authorize('view', $event);

        // Gone, not missing. A 404 would tell a crawler the link was wrong; the
        // link was right and the event is off, and the body says so in words.
        if ($event->status === EventStatus::Cancelled) {
            return response()->view('events.gone', ['event' => $event], 410);
        }

        // The going count the page prints, and the viewer's own answer the RSVP
        // control reads: one query here rather than one per component.
        $event->loadCount(['rsvps as going_count' => fn ($query) => $query->where('status', RsvpStatus::Going)]);
        $event->loadMissing('viewerRsvps');

        $response = response()->view('events.show', ['event' => $event]);

        // Moderator-only preview: keep it out of the index. Published pages send
        // no robots signal at all — see EventGoneTest.
        if ($event->status === EventStatus::Draft) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
