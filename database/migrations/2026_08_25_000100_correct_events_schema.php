<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Four corrections to the events table, as a new migration rather than an edit to
 * the shipped one. 2026_08_19_000200 has run on staging; editing it would mean the
 * schema depends on which environment you happened to migrate first.
 *
 *   event_key  the identifier we hand the bot. The bot keeps `event_key ->
 *              discord_event_id` and keys it on this string forever, so it cannot
 *              be the autoincrement id: staging's event 7 and production's event 7
 *              would be the same string and a shared bot would edit the wrong
 *              Discord event. ULID, unique, immutable (enforced on the model).
 *   game       the card asks for it by name; only `title` existed.
 *   timestamptz `->timestamp()` is `timestamp without time zone` on Postgres, which
 *              stores a wall-clock reading with no idea what it means. Paired with
 *              a `timezone` column holding an IANA identifier so "8pm London"
 *              survives a DST change: we store the instant, we render in the zone.
 *   ends_at    the bot requires it on every event.upsert. A job that has to invent
 *              a value for a required field is a bug waiting for a Friday, so the
 *              column is made NOT NULL and existing rows are backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            // Nullable to begin with: the constraints go on after the backfill, so
            // this runs on a database that already has rows.
            $table->string('event_key', 26)->nullable();
            $table->string('game')->nullable();
            $table->string('timezone', 64)->default('UTC');
        });

        DB::table('events')->whereNull('event_key')->orderBy('id')->each(function (object $event): void {
            DB::table('events')->where('id', $event->id)->update(['event_key' => (string) Str::ulid()]);
        });

        DB::table('events')->whereNull('ends_at')->update([
            'ends_at' => DB::raw("starts_at + interval '2 hours'"),
        ]);

        Schema::table('events', function (Blueprint $table): void {
            $table->unique('event_key');
        });

        // Name the reading's meaning explicitly. Left to itself Postgres reads the
        // session TimeZone, which is a property of whoever happens to run the
        // migration; every existing row was written as UTC by Laravel.
        DB::statement("alter table events alter column starts_at type timestamptz using starts_at at time zone 'UTC'");
        DB::statement("alter table events alter column ends_at type timestamptz using ends_at at time zone 'UTC'");

        DB::statement('alter table events alter column event_key set not null');
        DB::statement('alter table events alter column ends_at set not null');
    }

    public function down(): void
    {
        DB::statement('alter table events alter column ends_at drop not null');
        DB::statement("alter table events alter column starts_at type timestamp using starts_at at time zone 'UTC'");
        DB::statement("alter table events alter column ends_at type timestamp using ends_at at time zone 'UTC'");

        Schema::table('events', function (Blueprint $table): void {
            $table->dropUnique(['event_key']);
            $table->dropColumn(['event_key', 'game', 'timezone']);
        });
    }
};
