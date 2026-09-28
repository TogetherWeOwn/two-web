<?php

namespace App\Jobs;

use App\Enums\DataRequestStatus;
use App\Models\DataRequest;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The actual delete behind an approved deletion request (TOG-8705, runbook
 * docs/moderator-export-deletion.md).
 *
 * Approval and deletion are deliberately two steps. Approval is a moderator
 * decision recorded on the queue row; the delete runs here, after the
 * member-facing "approved" copy has told them to download anything they want
 * to keep. Re-running an approval must not double-delete: users.id is gone
 * after the first pass, so the job finds nothing and closes the row.
 *
 * What goes and what stays is the runbook's table, and the FKs enforce it:
 * profiles + RSVPs cascade off users; events/featured contents null the
 * creator; access-log viewer ids null; join attempts keep the snowflake.
 */
class DeleteMemberData implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $dataRequestId) {}

    public function handle(): void
    {
        $request = DataRequest::query()->find($this->dataRequestId);

        if ($request === null) {
            Log::warning('DeleteMemberData ran for a missing data request.', [
                'data_request_id' => $this->dataRequestId,
            ]);

            return;
        }

        if ($request->status !== DataRequestStatus::Approved) {
            return;
        }

        $userId = $request->getAttribute('user_id');

        if ($userId === null) {
            // Already deleted by an earlier pass — nothing left to do.
            return;
        }

        $user = User::query()->find($userId);

        DB::transaction(function () use ($request, $user): void {
            // The FKs do the work: cascade removes the profile and RSVPs,
            // nullOnDelete detaches created events, featured content and log
            // viewer ids. Deleting through the model (not a mass query) so
            // model events fire.
            $user?->delete();

            $request->forceFill(['user_id' => null])->save();
        });
    }
}
