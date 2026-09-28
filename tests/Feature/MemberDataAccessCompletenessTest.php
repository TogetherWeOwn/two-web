<?php

use App\Http\Controllers\ProfileController;
use App\Http\Middleware\RecordMemberDataAccess;
use App\Livewire\MemberProfile;
use App\Models\MemberDataAccessLog;
use App\Models\Profile;
use App\Models\User;
use App\Support\Profiles\MemberStats;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** @return array<int, string> */
function unloggedMemberDataRoutes(): array
{
    $memberRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->uri() === 'profile'
            || $route->uri() === 'members'
            || str_starts_with($route->uri(), 'members/')
            || str_starts_with($route->getActionName(), ProfileController::class.'@')
            || str_starts_with($route->getName() ?? '', 'profiles.'));

    // A renamed namespace must fail loudly rather than turn this into [] == [].
    // TOG-8440: PATCH /members/{user} (`profiles.update`) is deleted per the
    // TOG-8433 spec — MemberProfile::save() is the single writer — so the
    // guarded surface is the two read routes.
    expect($memberRoutes->map->getName()->all())
        ->toContain('profile', 'profiles.show');

    return $memberRoutes
        ->reject(fn ($route) => collect(Route::gatherRouteMiddleware($route))->contains(
            fn ($middleware) => is_string($middleware)
                && explode(':', $middleware, 2)[0] === RecordMemberDataAccess::class
        ))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri().' ('.$route->getName().')')
        ->values()->all();
}

it('logs one row for every authenticated member-profile view path', function (bool $hasProfile) {
    $viewer = User::factory()->create();
    $member = User::factory()->create();

    if ($hasProfile) {
        Profile::factory()->for($member)->create();
    }

    $this->actingAs($viewer)->get(route('profiles.show', $member))->assertOk();
    expect(MemberDataAccessLog::query()->count())->toBe(1);

    // This existing route serves HTML even for Accept: application/json. Both
    // request formats must still be logged; no new representation is introduced.
    $this->actingAs($viewer)->getJson(route('profiles.show', $member))->assertOk();
    expect(MemberDataAccessLog::query()->count())->toBe(2);

    foreach (MemberDataAccessLog::query()->get() as $log) {
        expect($log->viewer_user_id)->toBe($viewer->id)
            ->and($log->viewer_discord_id)->toBe($viewer->discord_id)
            ->and($log->subject_user_ids)->toBe([$member->id])
            ->and($log->subject_count)->toBe(1)
            ->and($log->resource)->toBe('member')
            ->and($log->action)->toBe('view')
            ->and($log->route)->toBe('profiles.show')
            ->and($log->occurred_at)->not->toBeNull();
    }

    $this->get(route('profile'))->assertOk();
    $this->get(route('profiles.show', $viewer))->assertOk();
    $this->getJson(route('profiles.show', $viewer))->assertOk();
    expect(MemberDataAccessLog::query()->count())->toBe(2); // viewer-own-record exclusion

    // TOG-8440: PATCH /members/{user} is deleted — the Livewire form is the
    // single writer and its update endpoint never runs this middleware, so a
    // self-edit writes no row here by construction. The save itself is pinned
    // in tests/Feature/Livewire/MemberProfileTest.php.
    expect(MemberDataAccessLog::query()->count())->toBe(2);
})->with(['without profile row' => false, 'with profile row' => true]);

it('fails when a member-data route ships without the access log', function () {
    expect(unloggedMemberDataRoutes())->toBe([]);
});

it('detects a newly added unlogged member route regardless of its parameter name', function () {
    Route::middleware(['web', 'auth'])->get('members/{member}/export', fn () => 'not implemented')
        ->name('test.member-export');

    expect(unloggedMemberDataRoutes())->toBe([
        'GET|HEAD members/{member}/export (test.member-export)',
    ]);
});

it('writes nothing for logged-out requests and authenticated 404s', function () {
    // Auth runs before route-model binding: logged-out missing members redirect
    // to login (or return JSON 401), while an authenticated missing member is 404.
    $member = User::factory()->create();
    $viewer = User::factory()->create();

    // TOG-8440: PATCH /members/{user} no longer exists. GET still serves the
    // member URL, so a PATCH on it is a 405 from route matching — before auth,
    // binding and middleware — never a write and never a log row.
    $this->get(route('profile'))->assertRedirect(route('login'));
    $this->get(route('profiles.show', $member))->assertRedirect(route('login'));
    $this->get('/members/999999999')->assertRedirect(route('login'));
    $this->patch('/members/999999999', ['bio' => 'smuggled'])->assertMethodNotAllowed();
    $this->getJson(route('profiles.show', $member))->assertUnauthorized();
    $this->getJson('/members/999999999')->assertUnauthorized();
    $this->patchJson('/members/999999999', ['bio' => 'smuggled'])->assertMethodNotAllowed();

    $this->actingAs($viewer)->get('/members/999999999')->assertNotFound();
    $this->getJson('/members/999999999')->assertNotFound();
    $this->actingAs($viewer)->patch('/members/999999999', ['bio' => 'missing'])->assertMethodNotAllowed();
    $this->actingAs($viewer)->patchJson('/members/999999999', ['bio' => 'missing'])->assertMethodNotAllowed();

    expect(MemberDataAccessLog::query()->count())->toBe(0)
        ->and($member->profile()->exists())->toBeFalse();
});

it('does not attribute a rejected profile update to the target as viewer', function () {
    // TOG-8440: the HTTP writer is gone, so the denial path is the Livewire
    // form — Gate::authorize('updateProfile') throws before any write, and
    // the Livewire update endpoint never runs this middleware, so no row.
    $viewer = User::factory()->create();
    $member = User::factory()->create();
    Profile::factory()->for($member)->create(['bio' => 'unchanged']);

    Livewire::actingAs($viewer)
        ->test(MemberProfile::class, [
            'member' => $member,
            'stats' => MemberStats::unavailable($member->discord_id),
        ])
        ->call('edit')
        ->assertForbidden();

    expect(MemberDataAccessLog::query()->count())->toBe(0)
        ->and($member->profile()->sole()->bio)->toBe('unchanged');
});

it('records a bound member in a JSON response without a second database read', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create();

    Route::middleware(['web', 'auth', 'member-access-log:member,view'])
        ->get('test/access-log/{user}', fn (User $user) => response()->json(['id' => $user->id]))
        ->name('test.bound-member');

    $this->actingAs($viewer)->getJson("test/access-log/{$member->id}")->assertExactJson(['id' => $member->id]);

    $log = MemberDataAccessLog::query()->sole();
    expect($log->viewer_user_id)->toBe($viewer->id)
        ->and($log->subject_user_ids)->toBe([$member->id])
        ->and($log->route)->toBe('test.bound-member');
});

it('refuses member-profile reads when the access log is unavailable', function (string $accept) {
    $viewer = User::factory()->create();
    $member = User::factory()->create(['username' => 'unserved-member-token']);
    Schema::drop('member_data_access_logs');

    $this->actingAs($viewer)->get(route('profiles.show', $member), ['Accept' => $accept])
        ->assertServiceUnavailable()
        ->assertDontSee('unserved-member-token');
})->with(['text/html', 'application/json']);
