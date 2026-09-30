<?php

namespace App\Http\Controllers;

use App\Enums\RsvpStatus;
use App\Http\Requests\StoreRsvpRequest;
use App\Http\Resources\RsvpResource;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use App\Support\RsvpRateLimit;
use App\Support\SpamTrap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * One answer per member per event, so this is a PUT and a DELETE on a singular
 * resource rather than a collection: there is nothing to list and no id to hand
 * back, and re-answering is an update of the same row.
 *
 * Nothing here waits on Discord. The row is committed, the write-back is dispatched
 * after the commit, and the member gets their answer back with
 * `synced_to_discord_at` still null — which is the truth, and is what the page
 * shows while the bot catches up.
 */
class RsvpController
{
    public function __construct(private readonly EventService $events) {}

    public function update(StoreRsvpRequest $request, Event $event): JsonResponse
    {
        $user = $request->actor();

        // TOG-8715: the honeypot swallow. A filled decoy never touches the
        // rate limiter, the authorizer beyond the trivial grant, or the
        // database — but it answers the byte-identical first-write success
        // shape (201, null mirror stamp), so the bot cannot tell the swallow
        // from a stored row. Nothing attacker-shaped is logged.
        if (SpamTrap::honeypotFilled($request->input(SpamTrap::HONEY_FIELD))) {
            return (new RsvpResource($this->decoyRsvp($event, $user, $request->status())))->response()->setStatusCode(201);
        }

        RsvpRateLimit::hit($user);

        $rsvp = $this->events->rsvp($event, $user, $request->status());

        return (new RsvpResource($rsvp))->response()->setStatusCode($rsvp->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, Event $event): Response
    {
        $user = $request->user();

        // TOG-8715: same swallow on the delete: a filled decoy discards nothing
        // and answers the same empty 204, with no database touch.
        if (! SpamTrap::honeypotFilled($request->input(SpamTrap::HONEY_FIELD))) {
            // No policy call: a member can only ever reach their own row here, because
            // the only row this can delete is the one keyed on the caller.
            if ($user instanceof User) {
                RsvpRateLimit::hit($user);
                $this->events->withdrawRsvp($event, $user);
            }
        }

        return response()->noContent();
    }

    /**
     * An unsaved answer echoing the requested status, so the resource can
     * render the decoy response without persisting anything. `wasRecentlyCreated`
     * stays false (the row was not created); the controller pins 201 itself
     * because the decoy always plays a first-time write.
     */
    private function decoyRsvp(Event $event, User $user, RsvpStatus $status): Rsvp
    {
        return new Rsvp([
            'event_id' => $event->getKey(),
            'user_id' => $user->getKey(),
            'status' => $status,
            'synced_to_discord_at' => null,
        ]);
    }
}
