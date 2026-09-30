<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TOG-8725: moderators cannot pause RSVPs without unpublishing. A
        // published event stays visible while taking no new answers, so the
        // pause is a flag rather than a status: unpublishing to the same end
        // would hide the event itself. Default true, so every existing row —
        // and every row written by a caller that does not know about the flag
        // yet — keeps today's behaviour: open.
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('rsvp_open')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('rsvp_open');
        });
    }
};
