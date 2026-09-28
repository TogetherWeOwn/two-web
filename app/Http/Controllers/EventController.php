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

    /**
     * The default page size for `GET /events.json`: 20 rows, enough for a
     * month of game nights without turning the response into the whole
     * archive. `MAX_PER_PAGE` caps `?per_page` at 100 — a larger ask is
     * clamped, not rejected, so a client typo cannot turn the listing back
     * into the unbounded query this replaced.
     */
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $request->integer('per_page', self::DEFAULT_PER_PAGE)));
        $page = max(1, $request->integer('page', 1));

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
            // Ties are real — a double-header starts two events at once — and
            // without a tiebreak those rows can drift between pages.
            ->orderBy('id')
            ->paginate($perPage, page: $page);

        $response = EventResource::collection($events)->response();

        // A strong validator over the exact bytes going out: the body already
        // bakes in the viewer's role (drafts for moderators only), the page
        // params and every `going_count`, so the hash covers all three without
        // an extra query. A repeat poll with `If-None-Match` answers 304 with
        // no body instead of the full listing.
        $content = $response->getContent();
        $response->setEtag(hash('sha256', $content === false ? '' : $content));
        $response->isNotModified($request);

        return $response;
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $event);

        // Gone, not missing: same rule as the shareable page (TOG-6781) — a
        // cancelled `event_key` answers 410 so clients can tell "called off"
        // apart from "never existed". The machine-readable reason mirrors the
        // EventNotOpenException shape (`reason` + `event_key`).
        if ($event->status === EventStatus::Cancelled) {
            return response()->json([
                'reason' => 'event_cancelled',
                'message' => 'This event was cancelled.',
                'event_key' => $event->event_key,
                'status' => $event->status->value,
            ], 410);
        }

        $response = (new EventResource($event))->response();

        // Moderator-only preview: keep it out of the index. Published rows send
        // no robots signal at all — see EventGoneTest.
        if ($event->status === EventStatus::Draft) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
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
