<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Recomputed from Discord on every login. We store the answer, not the
            // role list: "can this person moderate" is the only question the site
            // asks, and keeping a copy of somebody's roles would be member data we
            // have no shipped feature for.
            $table->boolean('is_moderator')->default(false);

            // When they joined the TWO server — not when they first used the site.
            // The profile shows this, and it comes from the guild member payload.
            $table->timestamp('discord_joined_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_moderator', 'discord_joined_at']);
        });
    }
};
