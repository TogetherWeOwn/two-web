<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRsvpRequest;
use App\Http\Resources\RsvpResource;
use App\Models\Event;
use App\Models\User;
use App\Services\EventService;
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
        $rsvp = $this->events->rsvp($event, $request->actor(), $request->status());

        return (new RsvpResource($rsvp))->response();
    }

    public function destroy(Request $request, Event $event): Response
    {
        $user = $request->user();

        // No policy call: a member can only ever reach their own row here, because
        // the only row this can delete is the one keyed on the caller.
        if ($user instanceof User) {
            $this->events->withdrawRsvp($event, $user);
        }

        return response()->noContent();
    }
}
