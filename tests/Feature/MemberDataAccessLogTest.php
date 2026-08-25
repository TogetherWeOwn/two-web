<?php

use App\Models\MemberDataAccessLog;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

// These tests are the requirement on TOG-355, one test per clause. The panel they
// protect (TOG-54) does not exist yet, so the routes below stand in for it: they
// carry the same middleware the panel is required to carry, which is the thing
// under test. When the panel ships, these stay — they are what stops the control
// being quietly removed from it.

/** Registers a route on the panel's stack without needing the panel. */
function panelRoute(string $uri, Closure $handler, string $action = 'view'): void
{
    Route::middleware(['web', 'auth', 'can:access-admin', "member-access-log:member,{$action}"])
        ->get($uri, $handler)
        ->name('test.panel.'.str_replace('/', '.', $uri));
}

it('records who viewed a member, when, and which member', function () {
    $moderator = User::factory()->moderator()->create();
    $subject = User::factory()->create();

    panelRoute('admin/members/{id}', fn (string $id) => (string) User::query()->findOrFail($id)->getKey());

    $this->actingAs($moderator)->get("admin/members/{$subject->id}")->assertOk();

    $log = MemberDataAccessLog::query()->sole();

    expect($log->viewer_discord_id)->toBe($moderator->discord_id)
        ->and($log->viewer_user_id)->toBe($moderator->id)
        ->and($log->subject_user_ids)->toBe([$subject->id])
        ->and($log->subject_count)->toBe(1)
        ->and($log->action)->toBe('view')
        ->and($log->occurred_at)->not->toBeNull();
});

it('identifies the viewer by Discord snowflake, so renaming or deleting them locally does not lose who it was', function () {
    // Same reasoning as matching moderator roles by ID. A username is renameable
    // in Discord at any time and the users row is deletable here; neither can be
    // the thing an investigation hangs on.
    $moderator = User::factory()->moderator()->create(['discord_id' => '326474832151838730']);
    $subject = User::factory()->create();

    panelRoute('admin/members/{id}', fn (string $id) => (string) User::query()->findOrFail($id)->getKey());
    $this->actingAs($moderator)->get("admin/members/{$subject->id}")->assertOk();

    $moderator->delete();

    $log = MemberDataAccessLog::query()->sole();

    expect($log->viewer_discord_id)->toBe('326474832151838730')
        ->and($log->viewer_user_id)->toBeNull();
});

it('records a listing as one access naming every member on the page', function () {
    $moderator = User::factory()->moderator()->create();
    $members = User::factory()->count(3)->create();

    panelRoute('admin/members', fn () => (string) User::query()->get()->count(), action: 'list');

    $this->actingAs($moderator)->get('admin/members')->assertOk();

    $log = MemberDataAccessLog::query()->sole();

    expect($log->action)->toBe('list')
        ->and($log->subject_count)->toBe(3)
        ->and($log->subject_user_ids)->toEqualCanonicalizing($members->pluck('id')->all());
});

it('records a profile read against the member it is about, not against the profile row', function () {
    $moderator = User::factory()->moderator()->create();
    $profile = Profile::factory()->for(User::factory()->create())->create(['bio' => 'I mostly play Helldivers.']);

    panelRoute('admin/profiles/{id}', fn (string $id) => (string) Profile::query()->findOrFail($id)->getKey());

    $this->actingAs($moderator)->get("admin/profiles/{$profile->id}")->assertOk();

    expect(MemberDataAccessLog::query()->sole()->subject_user_ids)->toBe([$profile->user_id]);
});

it('catches a screen that never declared what it read, because the record is what hydrated', function () {
    // The reason this is collected from Eloquent rather than declared per screen:
    // a new panel resource that forgets to declare its subjects is a read with no
    // record, which is silent. Here nothing declares anything and it is still
    // recorded.
    $moderator = User::factory()->moderator()->create();
    $subject = User::factory()->create();

    panelRoute('admin/anything', function () {
        User::query()->get();               // no note(), no declaration

        return 'ok';
    });

    $this->actingAs($moderator)->get('admin/anything')->assertOk();

    expect(MemberDataAccessLog::query()->sole()->subject_user_ids)->toBe([$subject->id]);
});

it('does not log a moderator loading their own record', function () {
    // The authenticated user is hydrated on every request. Without this, every
    // page view logs the viewer looking at themselves and the real signal drowns.
    $moderator = User::factory()->moderator()->create();

    panelRoute('admin/dashboard', fn () => 'ok');

    $this->actingAs($moderator)->get('admin/dashboard')->assertOk();

    expect(MemberDataAccessLog::query()->count())->toBe(0);
});

it('logs nothing on the ordinary site, where the middleware is not applied', function () {
    $member = User::factory()->create();
    $other = User::factory()->create();

    Route::middleware(['web', 'auth'])->get('members/{id}', fn (string $id) => (string) User::query()->findOrFail($id)->getKey());

    $this->actingAs($member)->get("members/{$other->id}")->assertOk();

    expect(MemberDataAccessLog::query()->count())->toBe(0);
});

it('refuses to serve the read when the access log cannot be written', function () {
    // The control, not a nicety. If a failed write returned the member data
    // anyway, the log would be a best-effort record and "who looked?" would be
    // answerable only for the days it happened to be up.
    config()->set('member_access_log.enforce', true);

    $moderator = User::factory()->moderator()->create();
    $subject = User::factory()->create(['username' => 'the-member-under-test']);

    // Returns member data, not just an id, so "was the read served anyway?" is
    // answerable by looking for a token that cannot appear in an error page by
    // coincidence.
    panelRoute('admin/members/{id}', fn (string $id) => User::query()->findOrFail($id)->username);

    Schema::drop('member_data_access_logs');

    $this->actingAs($moderator)
        ->get("admin/members/{$subject->id}")
        ->assertServiceUnavailable()
        ->assertDontSee('the-member-under-test');
});

it('keeps no copy of member data beyond the identifiers', function () {
    // The log must not become the second place a profile lives. If somebody adds
    // a column here for convenience — a username to save a join, a bio "for
    // context" — this fails and they have to argue for it on the ticket.
    expect(Schema::getColumnListing('member_data_access_logs'))->toEqualCanonicalizing([
        'id',
        'viewer_discord_id',
        'viewer_user_id',
        'resource',
        'action',
        'subject_user_ids',
        'subject_count',
        'route',
        'occurred_at',
    ]);
});

it('logs the route name and never the URL, because a search term about a member is member data', function () {
    $moderator = User::factory()->moderator()->create();
    $subject = User::factory()->create();

    panelRoute('admin/members/{id}', fn (string $id) => (string) User::query()->findOrFail($id)->getKey());

    $this->actingAs($moderator)->get("admin/members/{$subject->id}?search=dave")->assertOk();

    $log = MemberDataAccessLog::query()->sole();

    expect($log->route)->toBe('test.panel.admin.members.{id}')
        ->and(json_encode($log->getAttributes()))->not->toContain('dave');
});

it('is append only', function () {
    $log = MemberDataAccessLog::query()->create([
        'viewer_discord_id' => '1',
        'resource' => 'member',
        'action' => 'view',
        'subject_user_ids' => [1],
        'subject_count' => 1,
        'occurred_at' => now(),
    ]);

    expect(fn () => $log->update(['action' => 'nothing to see here']))->toThrow(RuntimeException::class)
        ->and(fn () => $log->delete())->toThrow(RuntimeException::class)
        ->and(MemberDataAccessLog::query()->sole()->action)->toBe('view');
});

it('prunes records past the retention window and keeps the ones inside it', function () {
    config()->set('member_access_log.retention_days', 90);

    $row = fn (string $when) => MemberDataAccessLog::query()->create([
        'viewer_discord_id' => '1',
        'resource' => 'member',
        'action' => 'view',
        'subject_user_ids' => [1],
        'subject_count' => 1,
        'occurred_at' => $when,
    ]);

    $row(now()->subDays(91));
    $kept = $row(now()->subDays(89));

    // Mass prune, so the append-only delete guard above does not block retention
    // and retention cannot be repurposed into a general delete.
    $this->artisan('model:prune', ['--model' => [MemberDataAccessLog::class]])->assertSuccessful();

    expect(MemberDataAccessLog::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('has retention scheduled, because a retention policy nothing runs is a promise', function () {
    $commands = collect(app(Schedule::class)->events())->map->command->filter();

    expect($commands->contains(fn (string $c) => str_contains($c, 'model:prune')
        && str_contains($c, 'MemberDataAccessLog')))->toBeTrue();
});
