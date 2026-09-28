<?php

namespace App\Services;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Jobs\DeleteMemberData;
use App\Models\DataRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Decides data requests: approve or reject, exactly once.
 *
 * The decision is row-locked inside a transaction, the same rule as
 * EventService's publish/cancel transitions: two moderators approving the
 * same row at the same moment produce one decision and one delete job, not
 * two. A decision on a row that is no longer pending is a no-op that returns
 * false — the panel hides those actions, this is the backstop.
 */
class DataRequestService
{
    /**
     * Short decision codes, the only values a rejection may carry. Codes, not
     * prose, and never member data — the runbook
     * (docs/moderator-export-deletion.md) says why.
     *
     * @var list<string>
     */
    public const REJECTION_REASONS = [
        'identity-mismatch',
        'withdrawn-by-member',
    ];

    /**
     * Approve a pending request. A deletion approval queues the actual delete
     * (DeleteMemberData) — the member-facing "approved" copy goes out first
     * so they can download anything they want to keep. An export approval
     * needs nothing further: the member downloads their own JSON at /profile.
     */
    public function approve(DataRequest $request, User $decider): bool
    {
        return DB::transaction(function () use ($request, $decider): bool {
            $fresh = DataRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null || $fresh->status !== DataRequestStatus::Pending) {
                return false;
            }

            $fresh->forceFill([
                'status' => DataRequestStatus::Approved,
                'decided_by' => $decider->getKey(),
                'decided_at' => now(),
                'decision_reason' => null,
            ])->save();

            if ($fresh->type === DataRequestType::Deletion) {
                DeleteMemberData::dispatch($fresh->getKey());
            }

            return true;
        });
    }

    /** Reject a pending request. Changes nothing about the member's data. */
    public function reject(DataRequest $request, User $decider, string $reason): bool
    {
        if (! in_array($reason, self::REJECTION_REASONS, true)) {
            throw new InvalidArgumentException("Unknown data-request rejection reason: {$reason}.");
        }

        return DB::transaction(function () use ($request, $decider, $reason): bool {
            $fresh = DataRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh === null || $fresh->status !== DataRequestStatus::Pending) {
                return false;
            }

            $fresh->forceFill([
                'status' => DataRequestStatus::Rejected,
                'decided_by' => $decider->getKey(),
                'decided_at' => now(),
                'decision_reason' => $reason,
            ])->save();

            return true;
        });
    }
}
