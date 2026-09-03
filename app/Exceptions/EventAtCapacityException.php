<?php

namespace App\Exceptions;

use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The last free slot went to somebody else.
 *
 * This is an ordinary outcome of two members answering at the same moment, not a
 * fault, so it renders as a 409 with a machine-readable reason rather than
 * reaching the error handler as a 500. The loser of the race gets this; see
 * EventService::rsvp() for why exactly one of them can.
 */
class EventAtCapacityException extends RuntimeException
{
    public function __construct(public readonly Event $event)
    {
        parent::__construct("Event {$event->event_key} is full.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'reason' => 'event_at_capacity',
            'message' => 'This event is full.',
            'event_key' => $this->event->event_key,
            'capacity' => $this->event->capacity,
        ], 409);
    }
}
