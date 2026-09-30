<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TOG-9278: hot-path index audit on rsvps/events. TOG-8731 covered
        // join_attempts only; these are the shapes every event page hits.
        //
        // rsvps(event_id, status): goingCount(), waitlistCount(),
        // waitlistPositionFor(), promoteWaitlist() and the going_count
        // withCount all constrain (event_id, status). The only index that
        // served them was unique(event_id, user_id), so every one of those
        // was a bitmap on event_id plus a heap filter on status. The
        // waitlist ORDER BY (created_at, id) sorts at most a handful of rows
        // per event in memory, so created_at stays out of the index.
        Schema::table('rsvps', function (Blueprint $table) {
            $table->index(['event_id', 'status'], 'rsvps_event_id_status_index');
        });

        // Partial: only unsynced rows. The SyncEventToDiscord sweep
        // (`event_id = ? AND synced_to_discord_at IS NULL`) and the
        // reconcile stale-EXISTS subquery probe exactly this set, which is
        // empty in normal operation — the index stays tiny and costs nothing
        // to maintain until a Discord outage, which is when reconcile hammers
        // it. Raw statement because Blueprint has no partial-index support.
        DB::statement(
            'CREATE INDEX rsvps_unsynced_event_id_index ON rsvps (event_id) WHERE synced_to_discord_at IS NULL'
        );

        // events(ends_at): the calendar upcoming list, the related-events
        // block, the 404 suggestions and the feed/home listings all bound
        // `ends_at >= now()` — the "still alive" predicate — and the shipped
        // (status, starts_at) index cannot bound that range, so they
        // seq-scanned. A single-column range index serves them all: the
        // planner bitmaps it and filters the handful of live rows on status,
        // or bitmap-ANDs it with the status index for `status = published`
        // shapes. Verified: E1 drops from seq-scan+sort (50 live rows pulled
        // out of 3060) to Bitmap Heap Scan on ends_at with 10 heap rows
        // removed by the status filter.
        //
        // Deliberately NOT (status, ends_at): measured at 3k-row
        // production-shaped volume, that composite never won a plan in any
        // state. In the reconcile backlog state (most rows published AND
        // finished) the pass matches most of the table, so any index is a
        // pessimisation and the planner correctly seq-scans. In steady state
        // (40 live published rows) `status = published` is already selective
        // enough on the shipped index. And (status, ends_at) cannot serve the
        // `status <> draft` listings at all — `<>` is not an indexable
        // condition for a btree's leading column.
        Schema::table('events', function (Blueprint $table) {
            $table->index(['ends_at'], 'events_ends_at_index');
        });

        // events(starts_at, id): the calendar, the past archive, the share
        // page's prev/next neighbours, the related-events block and the 404
        // suggestions all walk events in starts_at order (id breaks ties for
        // double-headers) with a small LIMIT. Without this they seq-scan and
        // sort; with it they are forward/backward ordered scans with no sort.
        Schema::table('events', function (Blueprint $table) {
            $table->index(['starts_at', 'id'], 'events_starts_at_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_starts_at_id_index');
            $table->dropIndex('events_ends_at_index');
        });

        DB::statement('DROP INDEX IF EXISTS rsvps_unsynced_event_id_index');

        Schema::table('rsvps', function (Blueprint $table) {
            $table->dropIndex('rsvps_event_id_status_index');
        });
    }
};
