<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Scoped machine ingress for the agent event slice (TOG-5510/web, Gate 2).
 *
 * Three tables plus three nullable columns on `events`. Nothing here touches
 * human routes: every new column is nullable and every new table is only
 * written by the agent ingress and its audit path.
 *
 *   agent_event_grants            the admitted grant record: who may call, on
 *                                 which guild, until when. Stores only a
 *                                 one-way SHA-256 verifier, never the opaque
 *                                 credential itself.
 *   agent_event_idempotency_keys  caller-scoped replay store. Unique on
 *                                 (grant_id, key): the same key plus the same
 *                                 normalized payload replays the original
 *                                 result, a different payload is a 409. Rows
 *                                 are never deleted, so a worker restart
 *                                 replays rather than re-executes.
 *   agent_event_audits            one row per ingress attempt, including
 *                                 denials, with reason codes and no secrets.
 *                                 Retained through expiry and rollback.
 *   events.agent_grant_id         immutable machine attribution. Null means a
 *                                 human-owned event; non-null means the grant
 *                                 that owns it. Unique, so one grant owns at
 *                                 most one proof event (nulls excluded in
 *                                 Postgres unique semantics: many nulls, one
 *                                 row per grant value).
 *   events.proof_marker           server-generated unique label for
 *                                 duplicate/cleanup observation. Null for
 *                                 human events.
 *   events.agent_version          optimistic-concurrency counter, bumped only
 *                                 by agent writes. Human writes never touch
 *                                 it, so human edits cannot silently invalidate
 *                                 an agent's expected version and vice versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_event_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('agent_id');
            $table->string('company_id');
            // The admitted audience. Staging only; production is rejected by
            // the service, and the column exists so the rejection compares
            // against a stored value rather than a constant.
            $table->string('guild_id');
            // SHA-256 hex of the opaque credential. Unique so the credential
            // alone identifies the grant: the service never trusts a
            // caller-supplied agent id as authentication.
            $table->string('verifier_hash', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('max_events')->default(1);
            $table->timestamps();

            $table->index('agent_id');
        });

        Schema::create('agent_event_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('grant_id')->constrained('agent_event_grants')->cascadeOnDelete();
            $table->string('key');
            // SHA-256 hex of the normalized request payload. Equality means
            // "transport retry, replay"; inequality under the same key is the
            // 409 the Gate 2 contract requires.
            $table->string('payload_digest', 64);
            $table->unsignedSmallInteger('status');
            $table->json('body');
            $table->string('event_key', 26)->nullable();
            $table->timestamps();

            $table->unique(['grant_id', 'key']);
        });

        Schema::create('agent_event_audits', function (Blueprint $table): void {
            $table->id();
            // Nullable: an unauthenticated denial has no grant to attribute.
            $table->foreignUuid('grant_id')->nullable()->constrained('agent_event_grants')->nullOnDelete();
            $table->string('operation', 32);
            $table->string('event_key', 26)->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('payload_digest', 64)->nullable();
            // Correlation across ingress, queue, HMAC and Discord result.
            $table->string('request_id');
            $table->string('result', 16);
            $table->string('reason_code', 64)->nullable();
            $table->string('discord_event_id')->nullable();
            $table->timestamps();

            $table->index(['grant_id', 'created_at']);
            $table->index('event_key');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->foreignUuid('agent_grant_id')->nullable()->constrained('agent_event_grants')->nullOnDelete();
            $table->string('proof_marker')->nullable()->unique();
            $table->unsignedInteger('agent_version')->default(0);

            // One proof event per grant. Nullable, and Postgres excludes nulls
            // from a unique index, so human events (null) are unaffected while
            // a second owned event for the same grant fails at the database —
            // which is what makes the quota hold under concurrency rather than
            // under good intentions.
            $table->unique('agent_grant_id');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropUnique(['agent_grant_id']);
            $table->dropForeign(['agent_grant_id']);
            $table->dropColumn(['agent_grant_id', 'proof_marker', 'agent_version']);
        });

        Schema::dropIfExists('agent_event_audits');
        Schema::dropIfExists('agent_event_idempotency_keys');
        Schema::dropIfExists('agent_event_grants');
    }
};
