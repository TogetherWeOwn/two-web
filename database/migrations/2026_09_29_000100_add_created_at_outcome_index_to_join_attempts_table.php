<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TOG-8731: join_attempts grows one row per join attempt and the
        // single-column outcome index cannot prune by time, so the retention
        // prune (`created_at < ?`) and any time-ranged admin query table-scan
        // as volume grows. (created_at, outcome) serves both shapes: the
        // leading column bounds the range, the second covers the per-outcome
        // grouping without touching the heap for the count.
        Schema::table('join_attempts', function (Blueprint $table) {
            $table->index(['created_at', 'outcome'], 'join_attempts_created_at_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::table('join_attempts', function (Blueprint $table) {
            $table->dropIndex('join_attempts_created_at_outcome_index');
        });
    }
};
