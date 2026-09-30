<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Server-side event view counts (TOG-8408): one row per event per day, not one
 * row per view.
 *
 * Third-party analytics are out, so the shareable event page counts its own
 * audience. The row is written pre-aggregated — `views` increments on today's
 * row, a new row starts tomorrow — which is why there is no rollup job and no
 * scheduler entry: the "daily rollup" is the write pattern, and the lifetime
 * total is a SUM over a handful of narrow rows. A popular event collects 365
 * rows a year, not a row per visitor.
 *
 * The unique key on (event_id, viewed_on) is the concurrency control: two
 * simultaneous first-views of the day race on the INSERT, the loser catches the
 * 23505 and increments instead (see RecordEventView, same pattern as
 * AgentEventService's idempotency store). Bot and crawler traffic never reaches
 * the write — it is excluded on the User-Agent before anything is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_view_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->date('viewed_on');
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'viewed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_view_counts');
    }
};
