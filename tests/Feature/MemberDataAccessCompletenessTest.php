<?php

use App\Http\Controllers\ProfileController;
use App\Http\Middleware\RecordMemberDataAccess;
use App\Models\MemberDataAccessLog;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

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
    expect($memberRoutes->map->getName()->all())
        ->toContain('profile', 'profiles.show', 'profiles.update');

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

    $this->patch(route('profiles.update', $viewer), ['bio' => 'self-edit'])
        ->assertRedirect(route('profiles.show', $viewer));
    expect(MemberDataAccessLog::query()->count())->toBe(2)
        ->and($viewer->profile()->sole()->bio)->toBe('self-edit');
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

    $this->get(route('profile'))->assertRedirect(route('login'));
    $this->get(route('profiles.show', $member))->assertRedirect(route('login'));
    $this->get('/members/999999999')->assertRedirect(route('login'));
    $this->patch(route('profiles.update', $member), ['bio' => 'smuggled'])->assertRedirect(route('login'));
    $this->getJson(route('profiles.show', $member))->assertUnauthorized();
    $this->getJson('/members/999999999')->assertUnauthorized();
    $this->patchJson(route('profiles.update', $member), ['bio' => 'smuggled'])->assertUnauthorized();

    $this->actingAs($viewer)->get('/members/999999999')->assertNotFound();
    $this->getJson('/members/999999999')->assertNotFound();
    $this->patch('/members/999999999', ['bio' => 'missing'])->assertNotFound();
    $this->patchJson('/members/999999999', ['bio' => 'missing'])->assertNotFound();

    expect(MemberDataAccessLog::query()->count())->toBe(0)
        ->and($member->profile()->exists())->toBeFalse();
});

it('does not attribute a rejected profile update to the target as viewer', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create();
    Profile::factory()->for($member)->create(['bio' => 'unchanged']);

    $this->actingAs($viewer)->patchJson(route('profiles.update', $member), ['bio' => 'smuggled'])
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
