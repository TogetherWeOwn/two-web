<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per rendered event search (TOG-8400). Normalized query plus
        // result count only: no user id, no session, no IP, no raw input — so
        // there is nothing here that identifies who searched. Guests search,
        // and a guest search must not mint a person-shaped row.
        Schema::create('event_search_logs', function (Blueprint $table) {
            $table->id();
            $table->string('normalized_query', 255);
            $table->unsignedInteger('result_count');
            $table->timestamp('occurred_at');

            // The admin listing always asks the same two questions: top
            // zero-result queries (normalized_query, result_count = 0) and
            // recency (occurred_at).
            $table->index('normalized_query');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_search_logs');
    }
};
