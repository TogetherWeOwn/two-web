<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * The autoincrement `id` is deliberately not in here. `event_key` is the
     * identifier everything outside the database uses — the routes, the bot, and
     * anything that ever has to line one of our events up with a Discord one.
     *
     * Both readings of the time go out: the instant, so a client can do arithmetic
     * on it, and the local wall time with its zone, so a client can render "8pm"
     * without having to know that it is 8pm in London and not where they are.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'event_key' => $this->event_key,
            'title' => $this->title,
            'game' => $this->game,
            'description' => $this->description,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'starts_at_local' => $this->startsAtLocal()->format('Y-m-d H:i'),
            'ends_at_local' => $this->endsAtLocal()->format('Y-m-d H:i'),
            'timezone' => $this->timezone,
            'location' => $this->location,
            'capacity' => $this->capacity,
            // Prefer the eager aggregate the listing selects: calling
            // `goingCount()` here would be a count query per row in a
            // collection response. The fallback keeps single-row responses
            // (show/store/update) working without a special query.
            'going_count' => $this->going_count ?? $this->goingCount(),
            'status' => $this->status->value,
            'synced_to_discord' => $this->discord_event_id !== null,
        ];
    }
}
