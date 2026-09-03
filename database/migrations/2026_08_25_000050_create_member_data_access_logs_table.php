<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who looked at member data through the admin panel, when, and at whose records.
 *
 * This table exists to answer one question after the fact: "who actually looked?"
 * The moderator role is configured by role *ID* so a rename in Discord cannot
 * silently hand out the panel — but role *membership* is still mutable by anyone
 * with Manage Roles in the Discord server, outside this system and outside our
 * review. This table is the only place that change becomes visible to us.
 *
 * It is deliberately not an analytics table. Identifiers and the shape of the
 * access, nothing else — see the column comments and the allowlist test in
 * tests/Feature/MemberDataAccessLogTest.php. A log of who read member data must
 * not itself become a second copy of member data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_data_access_logs', function (Blueprint $table) {
            $table->id();

            // The viewer as *Discord* knows them, for the same reason roles are
            // matched by snowflake: a display name is renameable and a local user
            // row is deletable, and neither can be the thing an investigation
            // hangs on. Stored as a string because a snowflake does not fit in a
            // signed 64-bit column safely and is never arithmetic.
            //
            // The literal 'unauthenticated' means member data was read on a
            // request with no signed-in user. That should be impossible; it is
            // recorded rather than dropped precisely because it is the shape of a
            // real defect, and a gap in the log would hide it.
            $table->string('viewer_discord_id');

            // Convenience join, not the identity. Nulled rather than cascaded on
            // delete: removing a member must not erase the record that somebody
            // was looking at member data.
            $table->foreignId('viewer_user_id')->nullable()->constrained('users')->nullOnDelete();

            // What kind of thing was read ('member') and how ('view' one record,
            // 'list' a page of them). Free-form strings on purpose — an enum here
            // would need a migration every time the panel grows a screen, and the
            // value is evidence, not control flow.
            $table->string('resource');
            $table->string('action');

            // Internal users.id values — never usernames, never profile contents.
            // An id is enough to answer "was this member's data read?" and carries
            // nothing on its own if the log is ever disclosed.
            $table->jsonb('subject_user_ids');

            // Denormalised so "who read 400 records in one request" is a plain
            // ORDER BY rather than a jsonb_array_length scan.
            $table->unsignedInteger('subject_count');

            // Where it happened. The route *name*, not the URL: a URL can carry a
            // search term somebody typed, and a search term about a member is
            // member data.
            $table->string('route')->nullable();

            $table->timestamp('occurred_at');
        });

        // No updated_at anywhere above, and that is the point: a row here is
        // append-only. The model refuses updates and deletes outright; retention
        // is the one exception and it runs as a mass delete that never loads a
        // model. The durable version of this control is a database grant — the
        // application role should hold INSERT and SELECT on this table and not
        // UPDATE or DELETE. See docs/member-data-access-log.md.

        // The two questions an investigation actually asks.
        Schema::table('member_data_access_logs', function (Blueprint $table) {
            // "What did this viewer look at, in this window?"
            $table->index(['viewer_discord_id', 'occurred_at']);

            // "What can we still see?" — also the column retention prunes on.
            $table->index('occurred_at');
        });

        // "Who looked at *this member*?" — a containment query over the jsonb
        // array, which needs GIN to be anything other than a full scan. Postgres
        // only, which this repo already is (see phpunit.xml).
        DB::statement(
            'CREATE INDEX member_data_access_logs_subject_user_ids_gin
             ON member_data_access_logs USING gin (subject_user_ids jsonb_path_ops)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('member_data_access_logs');
    }
};
