<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Recurring events (TOG-8399): the Sunday Squad is one series, not four
 * hand-made copies.
 *
 * The parent row owns the rule (`recurrence_frequency` plus a bound:
 * `recurrence_count`, `recurrence_ends_on`, or both). Each occurrence is an
 * ordinary event row pointing back at it (`parent_event_id`), with a sticky
 * 1-based `recurrence_index` — the parent is 1, the first materialised child
 * is 2. Ordinary rows matter: the calendar, the shareable page, the 410 on
 * cancel, RSVPs and the feeds all key off status alone, so none of them needs
 * to learn what a series is.
 *
 * The index is what makes `events:reconcile` safe to re-run: it tops up
 * indexes that were never created and leaves every existing row — including
 * an instance a moderator cancelled to skip a week — exactly alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('recurrence_frequency', 16)->nullable();
            $table->unsignedInteger('recurrence_count')->nullable();
            $table->date('recurrence_ends_on')->nullable();
            $table->foreignId('parent_event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->unsignedInteger('recurrence_index')->nullable();
            $table->index('parent_event_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropIndex(['parent_event_id']);
            $table->dropForeign(['parent_event_id']);
            $table->dropColumn([
                'recurrence_frequency',
                'recurrence_count',
                'recurrence_ends_on',
                'parent_event_id',
                'recurrence_index',
            ]);
        });
    }
};
