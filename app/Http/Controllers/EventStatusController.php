<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\EventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Publishing and cancelling. Separate from EventController because they are
 * different permissions with different consequences — publishing is what puts an
 * event in front of the guild, and cancelling is the one transition that cannot be
 * taken back — and because "update the event" and "announce the event" being the
 * same endpoint is how a typo becomes an announcement.
 */
class EventStatusController
{
    public function __construct(private readonly EventService $events) {}

    public function publish(Request $request, Event $event): JsonResponse
    {
        Gate::forUser($request->user())->authorize('publish', $event);

        return (new EventResource($this->events->publish($event)))->response();
    }

    public function cancel(Request $request, Event $event): JsonResponse
    {
        Gate::forUser($request->user())->authorize('cancel', $event);

        return (new EventResource($this->events->cancel($event)))->response();
    }
}
