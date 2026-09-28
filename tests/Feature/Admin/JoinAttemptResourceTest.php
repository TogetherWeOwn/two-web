<?php

use App\Enums\JoinOutcome;
use App\Filament\Resources\JoinAttempts\Pages\ListJoinAttempts;
use App\Models\JoinAttempt;
use App\Models\User;
use Livewire\Livewire;

// Read-only viewer for the join-attempt audit trail (TOG-8401): guest out,
// member 403, moderator in — plus proof the resource has no write surface.
// There are no create/edit pages to probe, so the write-side assertions go
// through the policy instead: every write verb denied for everyone,
// including moderators.

function joinAttemptRow(array $overrides = []): JoinAttempt
{
    return JoinAttempt::query()->create(array_merge([
        'outcome' => JoinOutcome::Denied,
        'source' => 'web',
        'request_id' => '01JVIEWERTEST',
        'discord_id' => '123456789',
    ], $overrides));
}

it('redirects a guest from the join attempts index to login', function () {
    $this->get('/admin/join-attempts')->assertRedirect(route('login'));
});

it('answers a plain member with 403 on the join attempts index', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)->get('/admin/join-attempts')->assertForbidden();
});

it('lets a moderator load the join attempts index', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);

    $this->actingAs($moderator)->get('/admin/join-attempts')->assertOk();
});

it('lets a moderator open a join attempt read-only', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $row = joinAttemptRow();

    $this->actingAs($moderator)->get("/admin/join-attempts/{$row->id}")->assertOk();
});

it('answers a plain member with 403 on the join attempt view page', function () {
    $member = User::factory()->create(['is_moderator' => false]);
    $row = joinAttemptRow();

    $this->actingAs($member)->get("/admin/join-attempts/{$row->id}")->assertForbidden();
});

it('shows the row and filters the list by outcome', function () {
    $moderator = User::factory()->moderator()->create();
    joinAttemptRow(['outcome' => JoinOutcome::Added, 'request_id' => '01JVIEWERADDED']);
    joinAttemptRow(['outcome' => JoinOutcome::Error, 'request_id' => '01JVIEWERERROR']);

    $this->actingAs($moderator);

    Livewire::test(ListJoinAttempts::class)
        ->assertCanSeeTableRecords(JoinAttempt::query()->get())
        ->filterTable('outcome', JoinOutcome::Added)
        ->assertCanSeeTableRecords(JoinAttempt::query()->where('outcome', JoinOutcome::Added)->get())
        ->assertCanNotSeeTableRecords(JoinAttempt::query()->where('outcome', JoinOutcome::Error)->get());
});

it('denies every write verb on join attempts, even for moderators', function () {
    $moderator = User::factory()->moderator()->create();
    $row = joinAttemptRow();

    expect($moderator->can('viewAny', JoinAttempt::class))->toBeTrue()
        ->and($moderator->can('view', $row))->toBeTrue()
        ->and($moderator->can('create', JoinAttempt::class))->toBeFalse()
        ->and($moderator->can('update', $row))->toBeFalse()
        ->and($moderator->can('delete', $row))->toBeFalse();
});
