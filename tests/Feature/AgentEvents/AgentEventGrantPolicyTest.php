<?php

use App\Models\AgentEventGrant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

// The grant-inventory rule: a grant row names the admitted machine caller,
// company and guild, so reading it is moderator-only. Writing one is denied
// to everybody, moderators included — a grant is admitted by the CISO outside
// the website, and the machine ingress authenticates by credential possession
// (see AgentEventService), never through this policy.

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->grant = AgentEventGrant::query()->create([
        'agent_id' => (string) Str::uuid(),
        'company_id' => (string) Str::uuid(),
        'guild_id' => '1545644954272137297',
        'verifier_hash' => AgentEventGrant::verifierFor('policy-test-credential'),
    ]);
});

it('opens the grant listing only to moderators', function () {
    expect(Gate::forUser($this->moderator)->allows('viewAny', AgentEventGrant::class))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('viewAny', AgentEventGrant::class))->toBeFalse()
        ->and(Gate::allows('viewAny', AgentEventGrant::class))->toBeFalse();
});

it('shows a grant only to moderators', function () {
    expect(Gate::forUser($this->moderator)->allows('view', $this->grant))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('view', $this->grant))->toBeFalse()
        ->and(Gate::allows('view', $this->grant))->toBeFalse();
});

it('lets nobody mint a grant, not even a moderator', function () {
    // Grants are admitted outside the website; no signed-in person mints one.
    expect(Gate::forUser($this->moderator)->allows('create', AgentEventGrant::class))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('create', AgentEventGrant::class))->toBeFalse();
});

it('lets nobody change a grant, not even a moderator', function () {
    expect(Gate::forUser($this->moderator)->allows('update', $this->grant))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('update', $this->grant))->toBeFalse();
});

it('lets nobody remove a grant, not even a moderator', function () {
    expect(Gate::forUser($this->moderator)->allows('delete', $this->grant))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('delete', $this->grant))->toBeFalse();
});
