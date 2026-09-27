<?php

namespace App\Http\Controllers;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\EventService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * JSON only. The calendar UI is a separate card; this is the domain reachable over
 * HTTP, so the UI, the Dusk journey and anything else drive the same code instead
 * of each growing their own copy of the capacity rule.
 */
class EventController
{
    public function __construct(private readonly EventService $events) {}

    public function index(Request $request): JsonResponse
    {
        $events = Event::query()
            ->unless(
                Gate::forUser($request->user())->allows('viewDrafts', Event::class),
                fn (Builder $query): Builder => $query->where('status', '!=', EventStatus::Draft->value),
            )
            // The resource renders `going_count` on every row, so aggregate it
            // once: without this the collection is a count query per event.
            ->withCount(['rsvps as going_count' => fn ($query) => $query->where('status', RsvpStatus::Going)])
            // The calendar always asks the same question, and the shipped migration
            // already put an index on (status, starts_at) to answer it.
            ->orderBy('starts_at')
            ->get();

        return EventResource::collection($events)->response();
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $event);

        return (new EventResource($event))->response();
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $event = $this->events->create($request->actor(), $request->toInput());

        return (new EventResource($event))->response()->setStatusCode(201);
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        return (new EventResource($this->events->update($event, $request->toInput())))->response();
    }
}
