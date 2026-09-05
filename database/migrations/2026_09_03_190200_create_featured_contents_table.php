<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the landing page shows because a moderator chose it: an
        // announcement, a spotlighted event, a community link. The landing page
        // (TWO-28) reads published rows ordered by position; everything else
        // here exists so a moderator can stage, reorder and retire entries
        // without a deploy — which is the whole of TOG-54.
        Schema::create('featured_contents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The landing page always asks the same question: published rows,
            // in the order the moderators arranged them.
            $table->index(['is_published', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('featured_contents');
    }
};
