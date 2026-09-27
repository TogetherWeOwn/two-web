<?php

namespace App\Http\Controllers;

use App\Services\AgentEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The scoped machine ingress: POST /api/agent-events (TOG-5510/web, Gate 2).
 *
 * One endpoint, five typed operations, one admitted caller. This controller
 * owns nothing but transport: the bearer credential comes off the
 * Authorization header, the JSON document becomes an array, and the service
 * answers with a status and a body. Every denial, replay and conflict below
 * is the service's decision, audited there with reason codes and no secrets.
 *
 * No session, no User, no moderator flag. The route lives in the `api` group
 * in routes/api.php — never in `web`, where CSRF and StartSession would make
 * a machine caller answerable to browser middleware.
 */
class AgentEventController
{
    public function __construct(private readonly AgentEventService $agentEvents) {}

    public function store(Request $request): JsonResponse
    {
        // The JSON document, exactly as sent: it is what the idempotency
        // digest is computed over, so query strings and form fields stay out.
        $body = $request->json()->all();

        $answer = $this->agentEvents->handle(
            is_array($body) ? $body : [],
            $request->bearerToken(),
        );

        return response()->json($answer['body'], $answer['status']);
    }
}
