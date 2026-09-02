<?php

namespace App\Exceptions;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A state transition the event is not in a position to make: publishing something
 * already cancelled, or answering an event Discord has never been shown.
 *
 * Separate from a policy failure on purpose. 403 means "not you"; this means "not
 * now", and a member who sees one should not be told the other.
 */
class EventNotOpenException extends RuntimeException
{
    public function __construct(public readonly Event $event, public readonly string $attempted)
    {
        parent::__construct(sprintf(
            'Cannot %s event %s while it is %s.',
            $attempted,
            $event->event_key,
            $event->status->value,
        ));
    }

    public static function forRsvp(Event $event): self
    {
        return new self($event, 'rsvp to');
    }

    public static function forTransition(Event $event, EventStatus $to): self
    {
        return new self($event, 'move to '.$to->value);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'reason' => 'event_not_open',
            'message' => $this->getMessage(),
            'event_key' => $this->event->event_key,
            'status' => $this->event->status->value,
        ], 409);
    }
}
