<?php

namespace App\Http\Resources;

use App\Models\Rsvp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rsvp
 */
class RsvpResource extends JsonResource
{
    /**
     * `synced_to_discord_at` is part of the member-visible contract, not debug
     * output: null is how the page knows to say "saved here, syncing to Discord"
     * instead of claiming a Discord event that does not exist yet.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'synced_to_discord_at' => $this->synced_to_discord_at?->toIso8601String(),
        ];
    }
}
