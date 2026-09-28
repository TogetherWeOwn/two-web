<?php

use App\Http\Middleware\RecordMemberDataAccess;
use App\Models\Event;
use App\Models\MemberDataAccessLog;
use App\Models\Profile;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\MemberDataAccess\AccessRecorder;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
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

it('records member-profile reads on the ordinary site', function () {
    $member = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($member)->get(route('profiles.show', $other))->assertOk();

    $log = MemberDataAccessLog::query()->sole();

    expect($log->viewer_user_id)->toBe($member->id)
        ->and($log->subject_user_ids)->toBe([$other->id])
        ->and($log->route)->toBe('profiles.show');
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

it('never puts member identifiers in the failure log line', function () {
    // The failure path is the one that runs when something is already wrong, which
    // is the moment nobody is reading carefully. A QueryException's message is the
    // failed statement with its bindings substituted in — the viewer's snowflake
    // and every subject id — and it goes to the ordinary application log, which
    // has neither this table's retention window nor its handling rules.
    //
    // Broken here the way production breaks: the table present and a column gone.
    // Dropping the whole table fails at statement *preparation* and produces a
    // message with no bindings in it — the single shape of failure that cannot
    // leak, and therefore the one shape that must not be what we test with.
    config()->set('member_access_log.enforce', true);

    $moderator = User::factory()->moderator()->create(['discord_id' => '424242424242424242']);
    $subject = User::factory()->create();

    panelRoute('admin/members/{id}', fn (string $id) => User::query()->findOrFail($id)->username);

    Schema::dropColumns('member_data_access_logs', ['subject_count']);

    $records = [];
    Log::listen(function ($message) use (&$records): void {
        $records[] = [$message->message, $message->context];
    });

    $this->actingAs($moderator)->get("admin/members/{$subject->id}")->assertServiceUnavailable();

    $logged = json_encode($records);

    expect($records)->not->toBeEmpty()
        ->and($logged)->toContain('QueryException')          // still says what broke
        ->and($logged)->toContain('42703')                   // ...and that it was a missing column
        ->and($logged)->not->toContain('424242424242424242') // never the viewer
        ->and($logged)->not->toContain('insert into');       // never the statement or its bindings
});

it('lets no admin-gated route ship without the access log', function () {
    // The subjects-from-Eloquent decision does not remove the risk of forgetting,
    // it relocates it: from every panel screen to one line on one middleware
    // stack. Forgetting that line has the identical failure mode — member data
    // served at 200 with no row and no error — and takes out the whole control at
    // once rather than one screen.
    //
    // So the line is not something to remember.
    //
    // This test was asleep from TOG-355 until TOG-1042. It selected on the string
    // `can:access-admin`, and the panel that shipped in TOG-54 does not gate that
    // way: Filament's Authenticate middleware asks User::canAccessPanel(). The
    // filter matched 0 of the 8 panel routes and the assertion was
    // `expect([])->toBe([])` — deleting RecordMemberDataAccess from
    // AdminPanelProvider left the whole suite green.
    //
    // Two lessons are built in below. Both sides are matched by *gate*, not by one
    // spelling of it — a route is admin-gated if it carries the alias OR Filament's
    // panel gate, and it is covered if it carries the recorder as the
    // `member-access-log` alias OR as the bare class. And the selected set is
    // asserted non-empty first, so the next change of gating style fails here
    // instead of quietly putting this test back to sleep.
    $isAdminGated = fn ($route) => collect($route->gatherMiddleware())->contains(
        fn ($m) => is_string($m) && (
            str_contains($m, 'can:access-admin')          // alias gating
            || $m === FilamentAuthenticate::class         // panel gating (User::canAccessPanel)
            || str_starts_with($m, 'panel:')              // any Filament panel group
        )
    );

    $recordsAccess = fn ($route) => collect($route->gatherMiddleware())->contains(
        fn ($m) => is_string($m) && (
            str_starts_with($m, 'member-access-log')      // registered by alias, with or without params
            || $m === RecordMemberDataAccess::class       // registered as the bare class
        )
    );

    $gated = collect(Route::getRoutes()->getRoutes())->filter($isAdminGated);

    // The guard on the guard. Without this the whole test degrades silently to a
    // tautology the moment nothing matches, which is exactly how it spent TOG-54.
    expect($gated)->not->toBeEmpty('no admin-gated route matched: this test has gone vacuous again, the gating style changed');

    $missing = $gated->reject($recordsAccess)->map(fn ($route) => $route->uri())->values()->all();

    expect($missing)->toBe([]);
});

it('refuses to serve a streamed response it could not record', function () {
    // A StreamedResponse's body is produced at send(), after every middleware has
    // run. So the recorder flushes against whatever was hydrated *before* the
    // controller returned — for a straight `stream(fn () => ...)` that is nothing
    // — and the member data then leaves the server with no row, and no error, and
    // a flush that returned false indistinguishably from "this page showed no
    // member data". A CSV export is the likeliest early panel screen and the
    // highest-value access to have a record of.
    config()->set('member_access_log.enforce', true);

    $moderator = User::factory()->moderator()->create();
    $subject = User::factory()->create(['username' => 'streamed-member-token']);

    panelRoute('admin/members/export', fn () => response()->stream(function () {
        echo User::query()->whereKeyNot(auth()->id())->value('username');
    }), action: 'list');

    $response = $this->actingAs($moderator)->get('admin/members/export');

    $response->assertServiceUnavailable();
    expect($response->getContent())->not->toContain('streamed-member-token')
        ->and(MemberDataAccessLog::query()->count())->toBe(0);
});

it('records a streamed response whose subjects were declared before it was returned', function () {
    // The other half of the rule above: refusing is for the case where we cannot
    // say what was read, not for streaming as such. A screen that reads its
    // members through Eloquent before returning the stream — or that calls
    // note() — is recordable and is recorded, and the export still works.
    $moderator = User::factory()->moderator()->create();
    $subject = User::factory()->create(['username' => 'streamed-member-token']);

    panelRoute('admin/members/export', function () {
        $members = User::query()->whereKeyNot(auth()->id())->get();

        return response()->stream(fn () => print ($members->pluck('username')->implode(',')));
    }, action: 'list');

    $response = $this->actingAs($moderator)->get('admin/members/export');

    $response->assertOk();
    expect($response->streamedContent())->toContain('streamed-member-token');

    $log = MemberDataAccessLog::query()->sole();

    expect($log->subject_user_ids)->toBe([$subject->id])
        ->and($log->action)->toBe('list');
});

it('attributes no read to a request that did not make it', function () {
    // arm() must inherit nothing and flush() must leave nothing armed. A recorder
    // that stays armed after flushing goes on collecting on every later request in
    // the same container, and the next panel request writes those subjects as its
    // own — a row saying a moderator viewed a member on the panel when the read
    // happened somewhere else entirely. In an evidence table a false positive
    // costs the same as a miss.
    //
    // PHP-FPM gives a fresh container per request, so this is not reachable in
    // production today. It is reachable here, which is where this control's
    // evidence comes from. The synthetic unlogged route must not attribute its
    // reads to the next logged request; real member-profile routes are logged.
    $moderator = User::factory()->moderator()->create();
    $onPanel = User::factory()->create();
    $offPanel = User::factory()->create();

    panelRoute('admin/members/{id}', fn (string $id) => (string) User::query()->findOrFail($id)->getKey());
    Route::middleware(['web', 'auth'])
        ->get('test/unlogged/{id}', fn (string $id) => (string) User::query()->findOrFail($id)->getKey())
        ->name('test.unlogged.member');

    $this->actingAs($moderator)->get("admin/members/{$onPanel->id}")->assertOk();
    $this->actingAs($moderator)->get("test/unlogged/{$offPanel->id}")->assertOk();
    $this->actingAs($moderator)->get("admin/members/{$onPanel->id}")->assertOk();

    $rows = MemberDataAccessLog::query()->orderBy('id')->get();

    expect(app(AccessRecorder::class)->isArmed())->toBeFalse()
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]->subject_user_ids)->toBe([$onPanel->id])
        ->and($rows[1]->subject_user_ids)->toBe([$onPanel->id]);
});

it('records a partially selected profile against the member it is about', function () {
    // `select('bio')` hydrates a Profile with no user_id on it, so attributing the
    // read by reading that property silently drops it — and what got served was
    // profile *contents*, not an identifier. This is ordinary Eloquent, which is
    // the path the design says is covered, so it cannot be left as a named gap
    // alongside the raw-SQL one.
    $moderator = User::factory()->moderator()->create();
    $profile = Profile::factory()->for(User::factory()->create())->create(['bio' => 'I mostly play Helldivers.']);

    panelRoute('admin/profiles', fn () => Profile::query()->select('id', 'bio')->get()->pluck('bio')->implode(','), action: 'list');

    $this->actingAs($moderator)->get('admin/profiles')->assertOk()->assertSee('Helldivers');

    expect(MemberDataAccessLog::query()->sole()->subject_user_ids)->toBe([$profile->user_id]);
});

it('refuses to serve a profile read it cannot attribute to a member', function () {
    // The residue of the case above: selected without its key *or* its user_id,
    // so there is nothing to resolve the owner from. What was served is a bio —
    // contents, not an identifier — and the honest answer to "whose?" is that we
    // do not know. So it is refused, on the same rule as everything else here: a
    // read that cannot be recorded is not served.
    config()->set('member_access_log.enforce', true);

    $moderator = User::factory()->moderator()->create();
    Profile::factory()->for(User::factory()->create())->create(['bio' => 'I mostly play Helldivers.']);

    panelRoute('admin/profiles', fn () => Profile::query()->select('bio')->get()->pluck('bio')->implode(','), action: 'list');

    $this->actingAs($moderator)->get('admin/profiles')
        ->assertServiceUnavailable()
        ->assertDontSee('Helldivers');

    expect(MemberDataAccessLog::query()->count())->toBe(0);
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

it('refuses to serve a user read it cannot attribute to a member', function () {
    // The User half of the Profile residue above. `select('username')` hydrates a
    // User with no key on it, so there is nothing to record the read against —
    // and unlike a Profile there is no owner key to resolve through (usernames
    // are mutable, display names are not unique). `value('username')` is the
    // same shape: it is `first(['username'])` underneath. Found by the TOG-5611
    // coverage audit: both shapes served member contents with no row and no
    // error before this test.
    config()->set('member_access_log.enforce', true);

    $moderator = User::factory()->moderator()->create();
    User::factory()->create(['username' => 'keyless-member-token']);

    panelRoute('admin/members/names', fn () => User::query()->select('username')->get()->pluck('username')->implode(','), action: 'list');

    $this->actingAs($moderator)->get('admin/members/names')
        ->assertServiceUnavailable()
        ->assertDontSee('keyless-member-token');

    expect(MemberDataAccessLog::query()->count())->toBe(0);
});

it('records a keyed partial select on users, because the key is what makes it attributable', function () {
    // The boundary of the refusal above: the same screen with the key selected
    // is an ordinary recorded read. This pins which side of the line a panel
    // dropdown or autocomplete has to stay on.
    $moderator = User::factory()->moderator()->create();
    $subject = User::factory()->create();

    panelRoute('admin/members/names', fn () => User::query()->select('id', 'username')->get()->pluck('username')->implode(','), action: 'list');

    $this->actingAs($moderator)->get('admin/members/names')->assertOk();

    expect(MemberDataAccessLog::query()->sole()->subject_user_ids)->toBe([$subject->id]);
});

it('records member data reached through a relation, because the relation hydrates', function () {
    // The likeliest future panel screen: an RSVP or attendance list that shows
    // who is going. Rsvp rows are not observed — only User and Profile are — but
    // reaching the member through `$rsvp->user` hydrates the User, and that is
    // what is recorded. If a screen ever shows members from RSVPs without
    // touching the relation (raw user_ids, a join), that is the pluck-shaped gap
    // below and it needs note().
    $moderator = User::factory()->moderator()->create();
    $member = User::factory()->create(['username' => 'rsvp-member-token']);
    $event = Event::factory()->create();
    Rsvp::factory()->for($event)->for($member, 'user')->create();

    panelRoute('admin/events/attendance', fn () => Rsvp::query()->get()->map(fn (Rsvp $rsvp) => $rsvp->user->username)->implode(','), action: 'list');

    $this->actingAs($moderator)->get('admin/events/attendance')->assertOk()->assertSee('rsvp-member-token');

    expect(MemberDataAccessLog::query()->sole()->subject_user_ids)->toBe([$member->id]);
});

it('records a pluck-shaped read the screen declares with note()', function () {
    // The gap the automatic path cannot close: `pluck()` runs on the query
    // builder and never hydrates a model, so the `retrieved` listener never
    // fires. Raw SQL and the bot's read-only views are the same shape. The
    // contract is that such a screen calls `note()` with the members it showed
    // — this pins that the escape hatch writes the row, so a reviewer naming
    // any of those paths gets a test rather than a shrug.
    $moderator = User::factory()->moderator()->create();
    $subject = User::factory()->create(['username' => 'plucked-member-token']);

    panelRoute('admin/members/names', function () use ($subject) {
        $names = User::query()->pluck('username');

        app(AccessRecorder::class)->note($subject->id);

        return $names->implode(',');
    }, action: 'list');

    $this->actingAs($moderator)->get('admin/members/names')->assertOk()->assertSee('plucked-member-token');

    $log = MemberDataAccessLog::query()->sole();

    expect($log->subject_user_ids)->toBe([$subject->id])
        ->and($log->action)->toBe('list');
});
