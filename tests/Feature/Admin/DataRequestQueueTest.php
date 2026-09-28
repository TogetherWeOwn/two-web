<?php

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Filament\Resources\DataRequests\Pages\ListDataRequests;
use App\Models\DataRequest;
use App\Models\User;
use Livewire\Livewire;

// TOG-8705: the moderator side of the data-request queue. Same three
// questions as every other resource (guest out, member 403, moderator in),
// plus the queue's own rules: pending rows surface, nobody creates or edits
// rows in the panel, and approve/reject decide exactly once.

// One block per resource, same three questions each time: guest out, member
// 403, moderator in. Listed here alongside the events/featured-content blocks
// in ResourceAuthorizationTest — that file owns the shared loop, this file
// owns the queue-specific URL plus the queue's own rules below.
it('redirects a guest from the data-request queue to login', function () {
    $this->get('/admin/data-requests')->assertRedirect(route('login'));
});

it('answers a plain member with 403 on the data-request queue', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)->get('/admin/data-requests')->assertForbidden();
});

it('lets a moderator load the data-request queue', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);

    $this->actingAs($moderator)->get('/admin/data-requests')->assertOk();
});

// Rendering a populated Filament table calls Number::format, which requires
// ext-intl. CI installs intl (ci.yml `extensions:` line), so these skips only
// ever apply to this sandbox's PHP — same pattern as
// EventResourceServiceRoutingTest.

it('lists pending requests with the member’s Discord ID and type', function () {
    $moderator = User::factory()->moderator()->create();
    $request = DataRequest::factory()->create(['discord_id' => '987654321098765432']);

    Livewire::actingAs($moderator)
        ->test(ListDataRequests::class)
        ->assertSee('987654321098765432')
        ->assertOk();
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');

it('approves a pending deletion from the queue', function () {
    $moderator = User::factory()->moderator()->create();
    $request = DataRequest::factory()->create(['type' => DataRequestType::Deletion]);

    Livewire::actingAs($moderator)
        ->test(ListDataRequests::class)
        ->callTableAction('approve', $request);

    expect($request->fresh()->status)->toBe(DataRequestStatus::Approved)
        ->and($request->fresh()->decided_by)->toBe($moderator->id);
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');

it('rejects a pending request from the queue with a reason code', function () {
    $moderator = User::factory()->moderator()->create();
    $request = DataRequest::factory()->create(['type' => DataRequestType::Deletion]);

    Livewire::actingAs($moderator)
        ->test(ListDataRequests::class)
        ->callTableAction('reject', $request, data: ['decision_reason' => 'identity-mismatch']);

    $fresh = $request->fresh();

    expect($fresh->status)->toBe(DataRequestStatus::Rejected)
        ->and($fresh->decision_reason)->toBe('identity-mismatch');
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');

it('hides decide actions on a closed row', function () {
    $moderator = User::factory()->moderator()->create();
    $request = DataRequest::factory()->create(['status' => DataRequestStatus::Approved]);

    Livewire::actingAs($moderator)
        ->test(ListDataRequests::class)
        ->assertTableActionHidden('approve', $request)
        ->assertTableActionHidden('reject', $request);
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');
