<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->bigInteger('ics_sequence')->default(0);
        });

        // Preserve the revision clients already saw; starting at zero would
        // make existing subscriptions ignore updates until the counter caught up.
        DB::statement(<<<'SQL'
            UPDATE events SET ics_sequence = GREATEST(0,
                FLOOR(EXTRACT(EPOCH FROM COALESCE(updated_at, created_at, TIMESTAMP '1970-01-01')))::bigint)
            SQL);

        // A database-owned counter covers quiet saves, bulk reconciliation and
        // stale job instances too. PostgreSQL serializes updates to each row;
        // OLD is the persisted revision, not the caller's potentially stale copy.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION advance_event_ics_sequence() RETURNS trigger AS $$
            DECLARE
                timestamp_sequence bigint;
            BEGIN
                timestamp_sequence := GREATEST(0,
                    FLOOR(EXTRACT(EPOCH FROM COALESCE(NEW.updated_at, NEW.created_at, TIMESTAMP '1970-01-01')))::bigint);
                IF TG_OP = 'INSERT' THEN
                    NEW.ics_sequence := timestamp_sequence;
                ELSIF NEW IS DISTINCT FROM OLD THEN
                    NEW.ics_sequence := GREATEST(OLD.ics_sequence + 1, timestamp_sequence);
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER events_ics_sequence
                BEFORE INSERT OR UPDATE ON events
                FOR EACH ROW EXECUTE FUNCTION advance_event_ics_sequence();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER events_ics_sequence ON events; DROP FUNCTION advance_event_ics_sequence();');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('ics_sequence');
        });
    }
};
