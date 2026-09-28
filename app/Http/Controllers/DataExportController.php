<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member's own data, on demand, as JSON (TOG-8705).
 *
 * Self-only by construction: the only row this can ever read is the
 * caller's. There is no `{user}` wildcard to smuggle, no id to forge —
 * the same shape as RsvpController::destroy. The download is generated on
 * demand and stored nowhere (runbook docs/moderator-export-deletion.md):
 * moderators never need a copy, so no copy is kept.
 *
 * Contents match the member-data model (docs/member-data-model.md): what
 * Discord owns (identity, derived fields) plus what the member owns
 * (profile) plus their RSVPs. Nothing else in the schema is member data —
 * access-log rows, join attempts and created events are system records
 * about the member, not the member's data, and stay out.
 */
class DataExportController
{
    public function __invoke(Request $request): JsonResponse
    {
        $member = $request->user();
        abort_unless($member instanceof User, 403);

        $member->loadMissing(['profile', 'rsvps.event']);

        $profile = $member->profile;

        return response()->json([
            'profile' => [
                'discord_id' => $member->discord_id,
                'username' => $member->username,
                'display_name' => $member->display_name,
                'avatar' => $member->avatar,
                'discord_joined_at' => $member->discord_joined_at?->toIso8601String(),
                'bio' => $profile?->bio,
                'games' => $profile?->games ?? [],
                'timezone' => $profile?->timezone,
            ],
            'rsvps' => $member->rsvps->map(fn ($rsvp): array => [
                'event_key' => $rsvp->event?->event_key,
                'event_title' => $rsvp->event?->title,
                'status' => $rsvp->status->value,
                'answered_at' => $rsvp->created_at?->toIso8601String(),
            ])->all(),
            'exported_at' => now()->toIso8601String(),
            // An attachment, not inline JSON: this is a download-my-data button,
            // not an API, and the browser should save the file rather than render
            // it. Still a plain JsonResponse — the access-log middleware refuses
            // streamed/file bodies it cannot record, and this is neither.
        ])->header('Content-Disposition', 'attachment; filename="together-we-own-data.json"');
    }
}
