<?php

use App\Livewire\MemberProfile;
use App\Models\Profile;
use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\Milestone;
use App\Support\SpamTrap;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

// TOG-8715: the profile form carries a minimum-fill-time trap — a save
// landing sooner than the floor after edit() opens the form is swallowed as a
// suspected bot write. Real members take longer than the floor, but Livewire
// tests run in milliseconds, so every save below must exercise a human-paced
// fill first. The trap reads Carbon's clock, and Livewire test calls run
// through the full component lifecycle in-process, so freezing time at the
// floor between opening the form and saving ages the stamp honestly — unlike
// `set()` on the Locked stamp, which the framework refuses (and which is
// exactly what a forged backdate attempt looks like). A `beforeEach` freeze
// (Pest scoping: defining beforeEach() here applies to this file only) would
// freeze the stamp at the same instant as the save, so each save instead calls
// the pause helper below. Trap-specific coverage lives in
// tests/Feature/Security/SpamTrapTest.php.
function pausePastFillFloor(): void
{
    // Freeze-then-travel: setTestNow() with no argument clears the mock, so
    // a lone travel() from a frozen clock only advances the frozen instant —
    // the freeze must come first to anchor "now" before the floor is added.
    // test() with no arguments returns a proxy to the running Pest case,
    // which is how the helper reaches freezeTime()/travel() without a
    // TestCase-typed parameter (Pest binds closures to PHPUnit's TestCase,
    // not the app's — see the 20-odd pre-existing actingAs() flags on this
    // file). phpstan cannot see through the proxy, so it flags these two
    // lines while every neighbouring line stays at its baseline count.
    // (Carbon::setTestNow() is reset by the framework after each test; the
    // RsvpButtonTest throttle test uses the same freeze/travel pair.)
    test()->freezeTime(); // @phpstan-ignore method.notFound
    test()->travel(SpamTrap::MIN_FILL_MS + 1000)->milliseconds(); // @phpstan-ignore method.notFound
}

function profileStats(string $discordId): MemberStats
{
    return MemberStats::available(
        discordId: $discordId,
        joinedAt: Carbon::parse('2024-03-01T12:00:00Z'),
        tenureDays: 900,
        rankKey: 'veteran',
        isCurrentMember: true,
        milestones: [
            new Milestone('joined', Carbon::parse('2024-03-01T12:00:00Z'), null),
            new Milestone('rank_changed', Carbon::parse('2025-04-02T18:30:00Z'), 'veteran'),
        ],
    );
}

it('renders a complete member profile from Discord and member-owned data', function () {
    $member = User::factory()->create([
        'display_name' => 'River',
        'username' => 'river-two',
        'discord_joined_at' => '2024-03-01 12:00:00',
    ]);
    Profile::factory()->for($member)->create([
        'bio' => 'Usually in co-op after work.',
        'games' => ['Helldivers 2', 'Minecraft'],
        'timezone' => 'Europe/London',
    ]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, [
            'member' => $member,
            'stats' => profileStats($member->discord_id),
        ])
        ->assertSee('River')
        ->assertSee('@river-two')
        ->assertSee('Usually in co-op after work.')
        ->assertSee('Helldivers 2')
        ->assertSee('Europe/London')
        ->assertSee('Veteran')
        ->assertSee('Rank Changed');
});

it('makes a brand-new members sparse profile look intentional', function () {
    $member = User::factory()->create(['display_name' => 'Wren', 'avatar' => null]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, [
            'member' => $member,
            'stats' => MemberStats::available(
                discordId: $member->discord_id,
                joinedAt: null,
                tenureDays: null,
                rankKey: null,
                isCurrentMember: true,
                milestones: [],
            ),
        ])
        ->assertSeeHtml('data-testid="profile-new-member"')
        ->assertSee('Your profile has room to grow.')
        ->assertSee('Add a bio, a few games and your timezone so people know when to find you.')
        ->assertSee('You haven\'t RSVP\'d to anything yet.', false)
        ->assertSeeHtml('data-testid="profile-avatar-fallback"')
        ->assertDontSee('No games added yet.');
});

it('keeps the rest of the profile available while bot stats are down', function () {
    $member = User::factory()->create(['display_name' => 'Rowan']);
    Profile::factory()->for($member)->create(['bio' => 'Here for the people.']);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, [
            'member' => $member,
            'stats' => MemberStats::unavailable($member->discord_id),
        ])
        ->assertSee('Rowan')
        ->assertSee('Here for the people.')
        ->assertSeeHtml('data-testid="profile-stats-unavailable"')
        ->assertSee('Activity is taking a breather.')
        ->assertSee('The rest of this profile is still here.');
});

it('exposes an accessible edit flow only to the profile owner', function () {
    $member = User::factory()->create();
    Profile::factory()->for($member)->create(['bio' => 'Before']);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->assertSeeHtml('data-testid="profile-edit-form"')
        ->assertSeeHtml('for="bio"')
        ->assertSeeHtml('for="games"')
        ->assertSeeHtml('for="timezone"')
        ->assertSee('Your name, avatar, join date and rank come from Discord.');

    $viewer = User::factory()->create();

    Livewire::actingAs($viewer)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->assertDontSeeHtml('data-testid="profile-edit"')
        ->assertDontSeeHtml('data-testid="profile-edit-form"');
});

it('saves member-owned fields and returns to the profile', function () {
    $member = User::factory()->create(['display_name' => 'Wren']);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', 'Usually on after work.')
        ->set('gamesText', "Minecraft\nHelldivers 2\nMinecraft")
        ->set('timezone', 'Europe/London')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.')
        ->assertSee('Usually on after work.')
        ->assertSee('Helldivers 2');

    expect($member->profile()->first())
        ->bio->toBe('Usually on after work.')
        ->games->toBe(['Minecraft', 'Helldivers 2'])
        ->timezone->toBe('Europe/London');
});

it('keeps the form open when the profile write fails and does not leak the reason', function () {
    $member = User::factory()->create();
    MemberProfile::$profileWriter = static fn () => throw new RuntimeException('host=10.0.0.4');

    try {
        Livewire::actingAs($member)
            ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
            ->call('edit')
            ->tap(fn () => pausePastFillFloor())
            ->set('bio', 'Still here.')
            ->call('save')
            ->assertSet('editing', true)
            ->assertSet('saveFailed', true)
            ->assertSee('That profile did not save.')
            ->assertSee('Your changes are still here. Try once more.')
            ->assertDontSee('10.0.0.4');
    } finally {
        MemberProfile::$profileWriter = null;
    }
});

it('keeps the form open and identifies fields when an edit fails validation', function () {
    $member = User::factory()->create();

    $component = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', str_repeat('a', 1001))
        ->set('timezone', 'BST')
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['bio', 'timezone'])
        ->assertSee('Check the highlighted fields and try again.');

    expect($component->html())
        ->toContain('role="alert"')
        ->toContain('aria-invalid="true"')
        ->toContain('aria-describedby="bio-error"')
        // TOG-8420: inline per-field errors announce through a polite live
        // region so screen-reader users hear them after the summary.
        ->toContain('id="bio-error"')
        ->toContain('aria-live="polite"')
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('Saving…');
});

it('saves an empty games list without errors', function () {
    $member = User::factory()->create();
    Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('gamesText', '')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->profile()->first()->games)->toBe([]);
});

/* ---------------------------------------------------------------------------
   Focus after the re-render (TOG-6957). Every profile state change unmounts
   the focused control — opening the form removes the Edit button, saving or
   cancelling removes the form — which drops keyboard focus to <body>. The
   component dispatches to itself so the view's listener can move focus to
   the new state: the form heading on open, the saved confirmation on a valid
   save, the error alert on an invalid save, the Edit button on cancel.
   --------------------------------------------------------------------------- */

it('dispatches a focus event to itself when the edit form opens', function () {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->assertDispatched('profile-state-changed');
});

it('dispatches a focus event to itself when the edit is cancelled', function () {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->call('cancel')
        ->assertSet('editing', false)
        ->assertDispatched('profile-state-changed');
});

it('dispatches a focus event to itself on a valid save', function () {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', 'Usually on after work.')
        ->call('save')
        ->assertSet('editing', false)
        ->assertDispatched('profile-state-changed');
});

it('dispatches a focus event to itself even when validation fails', function () {
    // The dispatch runs BEFORE $this->validate(), because a failed validate
    // throws ValidationException and aborts the method — anything dispatched
    // after it would never run. The listener moves focus to the error alert.
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', str_repeat('a', 1001))
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['bio'])
        ->assertDispatched('profile-state-changed');
});

it('makes the focus targets focusable so keyboard focus can move there', function () {
    // tabindex="-1": out of the tab order, but focus() works after the swap.
    $member = User::factory()->create();

    $open = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->html();

    expect($open)->toContain('id="edit-profile-heading" tabindex="-1"');

    $failed = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', str_repeat('a', 1001))
        ->call('save')
        ->html();

    expect($failed)->toContain('tabindex="-1"')
        ->toContain('data-testid="profile-edit-failed"');

    $saved = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', 'Usually on after work.')
        ->call('save')
        ->html();

    expect($saved)->toContain('tabindex="-1"')
        ->toContain('data-testid="profile-saved"');
});

/* ---------------------------------------------------------------------------
   Single writer (TOG-8440, per the TOG-8433 spec). PATCH /members/{user}
   (`profiles.update`) is deleted: ProfileController@update,
   UpdateProfileRequest and profileAttributes() are gone, and
   MemberProfile::save() is the only profile writer. These tests port the
   unique HTTP-path coverage from the deleted ProfileUpdateValidationTest and
   ProfileBackendTest onto the Livewire path, plus the route-removal pins.
   --------------------------------------------------------------------------- */

it('has no PATCH profile route: profiles.update is gone', function () {
    // GET /members/{user} still exists, so the framework answers a PATCH on
    // the same URI with 405, not 404 (same shape as GET /logout in
    // DiscordLoginTest). Either way the writer is gone: nothing is written.
    $member = User::factory()->create();

    $this->actingAs($member)
        ->patch("/members/{$member->id}", ['bio' => 'smuggled'])
        ->assertMethodNotAllowed();

    expect($member->profile()->exists())->toBeFalse();
});

it('has no profiles.update route name to generate', function () {
    expect(fn () => route('profiles.update', 1))
        ->toThrow(RouteNotFoundException::class);
});

it('only lets a member save the fields they own', function () {
    // Discord-owned columns are never fillable on Profile and the component
    // only writes bio/games/timezone — a forged payload through the form
    // shape changes the profile, never the user row.
    $member = User::factory()->create([
        'username' => 'before-name',
        'display_name' => 'Before Name',
        'discord_joined_at' => '2024-03-01 12:00:00',
        'is_moderator' => false,
    ]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', 'Now playing evenings.')
        ->set('gamesText', "  Minecraft \nValorant\nMinecraft")
        ->set('timezone', 'America/New_York')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->fresh())
        ->username->toBe('before-name')
        ->display_name->toBe('Before Name')
        ->is_moderator->toBeFalse()
        ->and($member->fresh()?->discord_joined_at?->toDateTimeString())->toBe('2024-03-01 12:00:00')
        ->and($member->profile()->first())
        ->bio->toBe('Now playing evenings.')
        ->games->toBe(['Minecraft', 'Valorant'])
        ->timezone->toBe('America/New_York');
});

it('rejects invalid fields without changing the profile', function () {
    // Field validation ($this->validate()) throws before the per-game checks
    // run, so gamesText stays valid here — its own rejections are pinned
    // below. (The deleted HTTP writer returned all three keys at once; the
    // Livewire order surfaces bio/timezone first.)
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create([
        'bio' => 'Before',
        'games' => ['Minecraft'],
        'timezone' => 'Europe/London',
    ]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', str_repeat('a', 1001))
        ->set('gamesText', 'Minecraft')
        ->set('timezone', 'europe/london')
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['bio', 'timezone']);

    expect($profile->fresh())
        ->bio->toBe('Before')
        ->games->toBe(['Minecraft'])
        ->timezone->toBe('Europe/London');
});

it('forbids saving another members profile', function () {
    $viewer = User::factory()->create();
    $member = User::factory()->create();

    Livewire::actingAs($viewer)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->assertForbidden();

    Livewire::actingAs($viewer)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->set('bio', 'Changed by somebody else.')
        ->call('save')
        ->assertForbidden();

    expect($member->profile()->exists())->toBeFalse();
});

it('saves a profile without games and clears removed fields', function () {
    $member = User::factory()->create();
    Profile::factory()->for($member)->create([
        'bio' => 'Before',
        'games' => ['Minecraft'],
        'timezone' => 'Europe/London',
    ]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', 'Still here, new bio.')
        ->set('gamesText', '')
        ->set('timezone', '')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->profile()->first())
        ->bio->toBe('Still here, new bio.')
        ->games->toBe([])
        ->timezone->toBeNull();
});

it('saves 21 lines with duplicates as 20 distinct games', function () {
    // TOG-6965: the HTTP writer counted raw lines, Livewire dedups first.
    // The spec keeps the Livewire order: trim, drop blanks, dedup, then the
    // >20-distinct rejection — so 21 lines collapsing to 20 save cleanly.
    $member = User::factory()->create();
    $lines = array_map(fn (int $i) => "Game {$i}", range(1, 20));
    $lines[] = 'Game 7';

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('gamesText', implode("\n", $lines))
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->profile()->first()->games)
        ->toBe(array_map(fn (int $i) => "Game {$i}", range(1, 20)));
});

it('rejects more than 20 distinct games without changing the profile', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create([
        'bio' => 'Before',
        'games' => ['Minecraft'],
        'timezone' => 'Europe/London',
    ]);
    $gamesText = implode("\n", array_map(fn (int $i) => "Game {$i}", range(1, 21)));

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('gamesText', $gamesText)
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['gamesText']);

    expect($profile->fresh())
        ->bio->toBe('Before')
        ->games->toBe(['Minecraft'])
        ->timezone->toBe('Europe/London');
});

it('rejects an overlong gamesText payload', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('gamesText', str_repeat('c', 1701))
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['gamesText']);

    expect($profile->fresh()->games)->toBe(['Minecraft']);
});

it('rejects a gamesText line longer than 80 characters', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('gamesText', "Minecraft\n".str_repeat('b', 81))
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['gamesText']);

    expect($profile->fresh()->games)->toBe(['Minecraft']);
});

it('accepts boundary values: a 1000-character bio, 20 games, 80-character names', function () {
    $member = User::factory()->create();
    $games = array_map(fn (int $i) => "Game {$i} ".str_repeat('x', 73), range(1, 20));
    // "Game NN " is 8-9 chars, so pad each entry to exactly 80.
    $games = array_map(fn (string $g) => substr($g.str_repeat('y', 80), 0, 80), $games);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', str_repeat('a', 1000))
        ->set('gamesText', implode("\n", $games))
        ->set('timezone', 'UTC')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->profile()->first())
        ->bio->toBe(str_repeat('a', 1000))
        ->games->toBe($games)
        ->timezone->toBe('UTC');
});

it('normalizes whitespace-only input to null', function () {
    $member = User::factory()->create();
    Profile::factory()->for($member)->create([
        'bio' => 'Before',
        'games' => ['Minecraft'],
        'timezone' => 'Europe/London',
    ]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', '   ')
        ->set('gamesText', 'Minecraft')
        ->set('timezone', '')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->profile()->first())
        ->bio->toBeNull()
        ->games->toBe(['Minecraft'])
        ->timezone->toBeNull();
});

it('drops blank game lines instead of rejecting them', function () {
    // The Livewire order is trim, drop blanks, dedup — blank *lines* in the
    // textarea vanish silently. (The deleted HTTP writer was strict about
    // blank *array entries*; that shape no longer exists.)
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('gamesText', "Minecraft\n\n  Helldivers 2  \nMinecraft")
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->profile()->first()->games)->toBe(['Minecraft', 'Helldivers 2']);
});

it('ignores a forged avatar while saving the profile fields', function () {
    // The component only writes bio/games/timezone; there is no avatar input
    // to forge through. The pin is that the user row is untouched by a save.
    $member = User::factory()->create(['avatar' => 'original-avatar-hash']);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', 'New bio.')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->fresh()->avatar)->toBe('original-avatar-hash')
        ->and($member->profile()->first()->bio)->toBe('New bio.');
});

it('stores markup but renders it escaped, never as live HTML', function () {
    $member = User::factory()->create(['display_name' => 'Wren']);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', '<script>alert("bio")</script>')
        ->set('gamesText', '<img src=x onerror=alert(2)>')
        ->call('save')
        ->assertSet('editing', false);

    // The payload is stored as-is (validation is about shape, not content);
    // the safety property is that the profile page never emits it raw.
    $this->actingAs($member)
        ->get(route('profiles.show', $member))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(&quot;bio&quot;)&lt;/script&gt;', escape: false)
        ->assertSee('&lt;img src=x onerror=alert(2)&gt;', escape: false)
        ->assertDontSee('<script>alert', escape: false)
        ->assertDontSee('<img src=x', escape: false);
});

it('rejects control bytes in bio instead of storing them', function (string $payload) {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['bio' => 'Before']);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', $payload)
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['bio']);

    expect($profile->fresh()->bio)->toBe('Before');
})->with([
    'NUL byte' => ['a'.chr(0).'b'],
    'SOH' => ["a\x01b"],
    'backspace' => ["a\x08b"],
    'form feed' => ["a\x0cb"],
    'DEL' => ["a\x7fb"],
]);

it('rejects control bytes in a gamesText line instead of throwing a 500', function (string $payload) {
    // Each payload was an unhandled SQLSTATE[22P05] QueryException → HTTP 500
    // on the deleted HTTP path; on the Livewire path it is a form error.
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('gamesText', $payload."\nMinecraft")
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['gamesText']);

    expect($profile->fresh()->games)->toBe(['Minecraft']);
})->with([
    'NUL byte' => ['a'.chr(0).'b'],
    'SOH' => ["a\x01b"],
    'DEL' => ["a\x7fb"],
]);

it('still accepts tabs and newlines in a multiline bio', function () {
    // Tab, LF and CR are the controls a bio legitimately needs; the rule
    // allows exactly those and rejects everything else in Cc.
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->tap(fn () => pausePastFillFloor())
        ->set('bio', "Line one.\nLine two.\tTabbed.")
        ->set('gamesText', 'Minecraft')
        ->call('save')
        ->assertSet('editing', false)
        ->assertSee('Profile saved.');

    expect($member->profile()->first()->bio)->toBe("Line one.\nLine two.\tTabbed.");
});

/* ---------------------------------------------------------------------------
   Expired session (TOG-8137). The form was opened signed in and the session
   died underneath it (SESSION_LIFETIME). The save/cancel arrives with nobody
   behind it — no user instance — so the component names the expiry and points
   at the way back in instead of hitting the gate's 403. Distinct from
   $saveFailed on purpose: retrying cannot succeed without logging in first.
   The form stays open with the member's input intact; only the write is
   refused. Mirrors the RSVP session-expired banner (TOG-8135).

   The tests render authenticated, then expire the session before the
   submit: a guest can never reach mount (the view gate 403s), so rendering
   signed out cannot produce the component at all.
   --------------------------------------------------------------------------- */

it('names the expired session with a way back in when the save arrives signed out', function () {
    $member = User::factory()->create();

    $component = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->set('bio', 'Still composing this.');

    // The session died underneath the open form (SESSION_LIFETIME).
    auth()->logout();

    $component->call('save')
        ->assertSet('sessionExpired', true)
        // The form stays open: the input was not eaten, only the write refused.
        ->assertSet('editing', true)
        ->assertSee('Your session expired.', false)
        ->assertSeeHtml('data-testid="profile-session-expired"')
        ->assertSee('Your changes are still here.')
        ->assertSee('Log in with Discord')
        // Not a retryable failure: nothing here may invite a retry that cannot help.
        ->assertDontSee('That profile did not save.', false)
        ->assertDontSeeHtml('data-testid="profile-edit-failed"')
        // The focus dispatch fires so keyboard users land on the banner.
        ->assertDispatched('profile-state-changed');

    expect($member->profile()->first())->toBeNull();
});

it('names the expired session when cancel arrives signed out', function () {
    $member = User::factory()->create();

    $component = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit');

    auth()->logout();

    $component->call('cancel')
        ->assertSet('sessionExpired', true)
        ->assertSee('Your session expired.', false)
        ->assertSeeHtml('data-testid="profile-session-expired"')
        ->assertDispatched('profile-state-changed');
});

it('announces the expired session as an alert, because it interrupted what they were doing', function () {
    $member = User::factory()->create();

    $component = Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->call('edit')
        ->set('bio', 'Still composing this.');

    auth()->logout();

    $html = $component->call('save')
        ->html();

    expect($html)->toContain('role="alert"')
        ->toContain('data-testid="profile-session-expired"')
        ->toContain('tabindex="-1"');
});

it('clears the expired banner on the next authenticated save', function () {
    $member = User::factory()->create();

    // A stale banner from an earlier expired round trip must not sit above a
    // later saved confirmation: cleared before the attempt, not after (same
    // reasoning as the RSVP reset).
    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->set('sessionExpired', true)
        ->call('save')
        ->assertSet('sessionExpired', false)
        ->assertDontSeeHtml('data-testid="profile-session-expired"');
});

it('clears the expired banner when the form is reopened', function () {
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(MemberProfile::class, ['member' => $member, 'stats' => profileStats($member->discord_id)])
        ->set('sessionExpired', true)
        ->call('edit')
        ->assertSet('sessionExpired', false)
        ->assertDontSeeHtml('data-testid="profile-session-expired"');
});
