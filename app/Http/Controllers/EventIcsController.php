<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Support\EventIcs;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * One event as a calendar download: `GET /events/{event_key}.ics`.
 *
 * This is deliberately not `EventController::show`. That route (`/events/{event}`,
 * inside the `auth` group) is JSON, and one URL must not serve two media types —
 * content-negotiating on the Accept header gives crawlers and curl different
 * answers. The `.ics` suffix keeps the calendar download on the same public,
 * shareable path family as the HTML page (`/e/{event}`) while staying an
 * unconditional `text/calendar` response.
 *
 * Public, like the page: a calendar client fetching the URL has no session, so a
 * login wall would make the download useless. The visibility rule is the same
 * `view` policy both other surfaces enforce — published (and cancelled/past) for
 * everyone, drafts for moderators only. An unknown key is a 404 from the implicit
 * binding; a draft 403s for non-moderators via the policy.
 */
final class EventIcsController
{
    public function __invoke(Request $request, Event $event): Response
    {
        Gate::authorize('view', $event);

        // `<event_key>.ics`, not `event.ics`: the filename is what a calendar
        // client names the subscription or download, and the key is stable and
        // unique where the title is neither.
        $response = response(EventIcs::for($event), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$event->event_key.'.ics"',
            'Cache-Control' => 'private, max-age=300',
        ]);

        // Same strong-validator-over-bytes pattern as the RSS feed (`TOG-7330`):
        // `DTSTAMP` rides the content clock (`EventIcs` stamps the row's
        // `updated_at`), so unchanged content is byte-identical and a repeat
        // poll with `If-None-Match` answers 304 with no body.
        $content = $response->getContent();
        $response->setEtag(hash('sha256', $content === false ? '' : $content));
        $response->isNotModified($request);

        return $response;
    }
}
