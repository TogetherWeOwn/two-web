<?php

namespace App\Http\Controllers;

use App\Enums\RsvpStatus;
use App\Models\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

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
 */
final class EventPageController
{
    public function __invoke(Event $event): View
    {
        Gate::authorize('view', $event);

        // The going count the page prints, and the viewer's own answer the RSVP
        // control reads: one query here rather than one per component.
        $event->loadCount(['rsvps as going_count' => fn ($query) => $query->where('status', RsvpStatus::Going)]);
        $event->loadMissing('viewerRsvps');

        return view('events.show', ['event' => $event]);
    }
}
