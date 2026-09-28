<?php

namespace App\Http\Controllers;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Models\DataRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The member's deletion ask (TOG-8705, runbook docs/moderator-export-deletion.md).
 *
 * Self-only by construction: the row always names the caller, never a
 * `{user}` wildcard — the same shape as RsvpController::destroy. One open
 * request per member: a double-clicked submit answers with the existing row
 * rather than a 500 (the partial unique index is the race backstop). A
 * decision on the row is a moderator act in the panel, never here.
 */
class DataDeletionRequestController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $member = $request->user();
        abort_unless($member instanceof User, 403);

        try {
            DataRequest::query()->firstOrCreate(
                [
                    'user_id' => $member->getKey(),
                    'status' => DataRequestStatus::Pending,
                ],
                [
                    'discord_id' => $member->discord_id,
                    'type' => DataRequestType::Deletion,
                ],
            );
        } catch (QueryException $e) {
            // A double-clicked submit can lose the SELECT-then-INSERT race
            // against itself despite the partial unique index backstop. The
            // row exists — that is the whole ask — so carry on to the same
            // confirmation instead of 500ing. Anything else rethrows.
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }

        return redirect()->route('profile')->with(
            'data_request_status',
            'Deletion requested — a moderator will review it. Download anything you want to keep first.'
        );
    }
}
