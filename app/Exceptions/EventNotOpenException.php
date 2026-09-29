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
    public function __construct(
        public readonly Event $event,
        public readonly string $attempted,
        public readonly string $reason = 'event_not_open',
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf(
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

    /**
     * A moderator pause (TOG-8725), not a cancellation: the event is still
     * Published, so "while it is published" would read as nonsense. Its own
     * reason lets clients tell "paused" apart from "called off", and its own
     * message is the member-facing copy in words rather than a state dump.
     */
    public static function forRsvpClosed(Event $event): self
    {
        return new self($event, 'rsvp to', 'rsvp_closed', 'RSVPs are paused for this event — check back soon.');
    }

    public static function forTransition(Event $event, EventStatus $to): self
    {
        return new self($event, 'move to '.$to->value);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'reason' => $this->reason,
            'message' => $this->getMessage(),
            'event_key' => $this->event->event_key,
            'status' => $this->event->status->value,
        ], 409);
    }
}
