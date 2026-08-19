<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rsvps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status');

            // Null means "Discord does not know about this yet". The queued job that
            // talks to the bot stamps it. When the bot is down this is how the page
            // knows to say "saved here, syncing to Discord" instead of lying.
            $table->timestamp('synced_to_discord_at')->nullable();
            $table->timestamps();

            // One member, one answer per event.
            $table->unique(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rsvps');
    }
};
