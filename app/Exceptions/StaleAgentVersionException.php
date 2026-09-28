<?php

namespace App\Exceptions;

use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An agent update that lost a race: the event moved since the caller read it.
 *
 * A 409 with a machine-readable reason, not a silent overwrite. The caller
 * re-reads (the `read` verb is rate-limited separately and generously) and
 * retries with the fresh version. Separate from EventNotOpenException on
 * purpose: "not now" and "somebody else moved it" want different retries.
 */
class StaleAgentVersionException extends RuntimeException
{
    public function __construct(public readonly Event $event, public readonly int $expectedVersion)
    {
        parent::__construct(sprintf(
            'Event %s is at agent version %d, not the expected %d.',
            $event->event_key,
            $event->agent_version,
            $expectedVersion,
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'reason' => 'stale_version',
            'message' => $this->getMessage(),
            'event_key' => $this->event->event_key,
            'agent_version' => $this->event->agent_version,
        ], 409);
    }
}
