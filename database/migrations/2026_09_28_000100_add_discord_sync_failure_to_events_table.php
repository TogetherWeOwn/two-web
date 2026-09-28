<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A terminal bot refusal must not look like a transient outage (TOG-6990).
        // Before these columns, a refused write-back left `discord_event_id` and
        // `synced_to_discord_at` null — exactly the shape the reconcile pass
        // matches on — so it re-dispatched the refused operation every ten
        // minutes forever, writing a `failed_jobs` row and spending bot budget
        // on an answer already given. The stamp makes "never retry" visible to
        // the reconcile query; a genuinely new member or moderator action clears
        // it (EventService::syncAfterCommit) so the next attempt is re-armed.
        Schema::table('events', function (Blueprint $table): void {
            $table->timestamp('discord_sync_failed_at')->nullable();
            $table->string('discord_sync_failure_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['discord_sync_failed_at', 'discord_sync_failure_code']);
        });
    }
};
