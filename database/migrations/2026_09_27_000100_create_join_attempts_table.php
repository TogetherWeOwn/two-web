<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per join attempt, terminal outcome only (TOG-5617). No user
        // FK: most failing attempts have no user row, and the join must never
        // block on one. Never persist access tokens, exception messages or
        // error_description here — outcome, source, request_id and discord_id
        // are the whole schema on purpose.
        Schema::create('join_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('outcome');
            $table->string('source', 64)->nullable();
            $table->string('request_id')->nullable();
            $table->string('discord_id')->nullable();
            $table->timestamps();

            // The admin funnel widget always asks the same question: counts
            // per outcome.
            $table->index('outcome');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('join_attempts');
    }
};
