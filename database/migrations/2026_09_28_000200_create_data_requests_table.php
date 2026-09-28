<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The moderator-visible queue behind the member's /profile data section
        // (TOG-8705, runbook docs/moderator-export-deletion.md). One row per
        // member ask: an export question or a deletion request. The decision and
        // its metadata stay here 90 days after close; the exported JSON itself
        // is never stored — it is generated on demand at download time.
        Schema::create('data_requests', function (Blueprint $table) {
            $table->id();

            // Convenience join, not the identity — same rule as
            // member_data_access_logs.viewer_user_id. Approving a deletion
            // removes the member row, and that must not erase the record of
            // the decision. Nulled, never cascaded.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // The stable identity moderators verify against (docs/member-data-model.md
            // Rule 0): the Discord snowflake, not the renameable username. A plain
            // string with no FK — it survives the member row it names.
            $table->string('discord_id');

            $table->string('type');
            $table->string('status')->default('pending');

            // Who decided, nulled if they are later deleted — same reason as user_id.
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // Short decision code (`identity-mismatch`) or moderator note. Never
            // member data — the runbook says why.
            $table->string('decision_reason')->nullable();

            $table->timestamps();

            // The queue screen always asks the same question: what is still open.
            $table->index(['status', 'created_at']);
            $table->index('discord_id');
        });

        // One open request per member. A double-clicked submit must not create
        // two pendings, and the service-level check has a race — this does not.
        // Partial: closed rows are history and may repeat. NULL user_ids never
        // conflict in Postgres, so rows orphaned by a member delete stay out of
        // each other's way.
        DB::statement(
            "CREATE UNIQUE INDEX data_requests_one_open_per_member
             ON data_requests (user_id) WHERE status = 'pending'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
    }
};
