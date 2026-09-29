<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_votes', function (Blueprint $table) {
            $table->id();

            // Stable FAQ slug from App\Support\FaqEntry, not the question text:
            // rewording a question keeps its vote history.
            $table->string('entry');
            $table->boolean('helpful');

            // Exactly one of these is set. Signed-in members are keyed by
            // account; signed-out visitors by a random first-party
            // `faq_voter` UUID cookie — no PII, no IP stored anywhere.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('voter_key', 64)->nullable();

            $table->timestamps();

            $table->index('entry');

            // One vote per voter per entry. Re-voting updates the row
            // (FaqVoteController), so these pairs also stay unique.
            // Nullable halves are fine: Postgres treats NULLs as distinct,
            // so member rows (null voter_key) never collide with each other
            // here, and guest rows (null user_id) never collide there.
            $table->unique(['entry', 'user_id']);
            $table->unique(['entry', 'voter_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_votes');
    }
};
