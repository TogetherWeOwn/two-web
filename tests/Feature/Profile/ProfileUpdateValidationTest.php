<?php

use App\Livewire\MemberProfile;
use App\Models\Profile;
use App\Models\User;
use App\Support\Profiles\MemberStats;
use App\Support\Profiles\MemberStatsSource;
use Livewire\Livewire;

// TOG-5622: ProfileController@update validation hardening. The happy path and
// the basic invalid trio (long bio, long game, bad timezone) live in
// ProfileBackendTest; this file pins the edges around them — the games count
// and games_text caps, non-string payloads, boundary values, whitespace
// normalization, forged Discord-owned fields (avatar was missing), JSON 422s,
// and proof that stored markup renders escaped.

// ProfileBackendTest defines availableMemberStats(); this file must not
// redeclare it, so the render helper here has its own name.
function hardenedUnavailableStats(string $discordId): MemberStats
{
    return MemberStats::unavailable($discordId);
}

beforeEach(function () {
    $source = Mockery::mock(MemberStatsSource::class);
    $source->shouldReceive('forMember')
        ->andReturnUsing(fn (string $discordId) => hardenedUnavailableStats($discordId));
    app()->instance(MemberStatsSource::class, $source);
});

it('rejects more than 20 games without changing the profile', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create([
        'bio' => 'Before',
        'games' => ['Minecraft'],
        'timezone' => 'Europe/London',
    ]);
    $games = array_map(fn (int $i) => "Game {$i}", range(1, 21));

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), ['games' => $games])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['games']);

    expect($profile->fresh())
        ->bio->toBe('Before')
        ->games->toBe(['Minecraft'])
        ->timezone->toBe('Europe/London');
});

it('rejects an overlong games_text payload', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), ['games_text' => str_repeat('c', 1701)])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['games_text']);

    expect($profile->fresh()->games)->toBe(['Minecraft']);
});

it('rejects a games list that is not an array', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), ['games' => 'Minecraft'])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['games']);

    expect($profile->fresh()->games)->toBe(['Minecraft']);
});

it('rejects non-string game entries', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), ['games' => [123, ['nested']]])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['games.0', 'games.1']);

    expect($profile->fresh()->games)->toBe(['Minecraft']);
});

it('rejects a games_text line longer than 80 characters', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), ['games_text' => "Minecraft\n".str_repeat('b', 81)])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['games.1']);

    expect($profile->fresh()->games)->toBe(['Minecraft']);
});

it('rejects a non-string bio and a lowercase timezone', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create([
        'bio' => 'Before',
        'timezone' => 'Europe/London',
    ]);

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), [
            'bio' => ['not-a-string'],
            'timezone' => 'europe/london',
        ])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['bio', 'timezone']);

    expect($profile->fresh())
        ->bio->toBe('Before')
        ->timezone->toBe('Europe/London');
});

it('returns 422 JSON validation errors for API-style requests', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['bio' => 'Before']);

    $this->actingAs($member)
        ->patchJson(route('profiles.update', $member), ['bio' => str_repeat('a', 1001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bio']);

    expect($profile->fresh()->bio)->toBe('Before');
});

it('accepts boundary values: a 1000-character bio, 20 games, 80-character names', function () {
    $member = User::factory()->create();
    $games = array_map(fn (int $i) => "Game {$i} ".str_repeat('x', 73), range(1, 20));
    // "Game NN " is 8-9 chars, so pad each entry to exactly 80.
    $games = array_map(fn (string $g) => substr($g.str_repeat('y', 80), 0, 80), $games);

    $this->actingAs($member)
        ->patch(route('profiles.update', $member), [
            'bio' => str_repeat('a', 1000),
            'games' => $games,
            'timezone' => 'UTC',
        ])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasNoErrors();

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

    $this->actingAs($member)
        ->patch(route('profiles.update', $member), [
            'bio' => '   ',
            'games' => ['Minecraft'],
            'timezone' => '',
        ])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasNoErrors();

    expect($member->profile()->first())
        ->bio->toBeNull()
        ->games->toBe(['Minecraft'])
        ->timezone->toBeNull();
});

it('rejects blank game entries sent as an array', function () {
    // Blank *lines* are fine via games_text (split, trim, drop in
    // profileAttributes), but a blank *array entry* becomes null under
    // ConvertEmptyStringsToNull and fails the `string` rule — a 422, not a
    // silent drop. Pin it: the API-shaped input stays strict.
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), ['games' => ['  ', 'Minecraft', '']])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['games.0', 'games.2']);

    expect($profile->fresh()->games)->toBe(['Minecraft']);
});

it('splits games_text into lines on update', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->patch(route('profiles.update', $member), [
            'games_text' => "Minecraft\n\n  Helldivers 2  \nMinecraft",
        ])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasNoErrors();

    expect($member->profile()->first()->games)->toBe(['Minecraft', 'Helldivers 2']);
});

it('ignores a forged avatar while saving the profile fields', function () {
    // display_name/username/is_moderator forgery is pinned in
    // ProfileBackendTest; avatar is the remaining Discord-owned field and it
    // was never sent, so send it here.
    $member = User::factory()->create(['avatar' => 'original-avatar-hash']);

    $this->actingAs($member)
        ->patch(route('profiles.update', $member), [
            'bio' => 'New bio.',
            'avatar' => 'https://evil.example/x.png',
        ])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasNoErrors();

    expect($member->fresh()->avatar)->toBe('original-avatar-hash')
        ->and($member->profile()->first()->bio)->toBe('New bio.');
});

it('stores markup but renders it escaped, never as live HTML', function () {
    $member = User::factory()->create(['display_name' => 'Wren']);

    $this->actingAs($member)
        ->patch(route('profiles.update', $member), [
            'bio' => '<script>alert("bio")</script>',
            'games' => ['<img src=x onerror=alert(2)>'],
        ])
        ->assertRedirect(route('profiles.show', $member));

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

// TOG-6964: NUL/control bytes used to slip past validation. A NUL in `bio`
// was silently truncated by Postgres (`a\0b` stored as `61`), other C0
// controls and DEL were stored verbatim and rendered raw through Blade
// escaping, and a NUL in `games`/`games_text` blew up as an unhandled
// SQLSTATE[22P05] QueryException (HTTP 500). Every shape below is a
// validation failure now, on both the HTTP and Livewire edit paths.

it('rejects control bytes in bio instead of storing them', function (string $payload) {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['bio' => 'Before']);

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), ['bio' => $payload])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['bio']);

    expect($profile->fresh()->bio)->toBe('Before');
})->with([
    'NUL byte' => ['a'.chr(0).'b'],
    'SOH' => ["a\x01b"],
    'backspace' => ["a\x08b"],
    'form feed' => ["a\x0cb"],
    'DEL' => ["a\x7fb"],
]);

it('returns 422 for control bytes in bio on API-style requests', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['bio' => 'Before']);

    $this->actingAs($member)
        ->patchJson(route('profiles.update', $member), ['bio' => 'a'.chr(0).'b'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bio']);

    expect($profile->fresh()->bio)->toBe('Before');
});

it('rejects NUL bytes in games entries instead of throwing a 500', function (string $payload) {
    // Each payload was an unhandled SQLSTATE[22P05] QueryException → HTTP 500.
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), ['games' => [$payload]])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['games.0']);

    expect($profile->fresh()->games)->toBe(['Minecraft']);
})->with([
    'NUL byte' => ['a'.chr(0).'b'],
    'SOH' => ["a\x01b"],
    'DEL' => ["a\x7fb"],
]);

it('rejects NUL bytes in a games_text line instead of throwing a 500', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create(['games' => ['Minecraft']]);

    $this->actingAs($member)
        ->from(route('profiles.show', $member))
        ->patch(route('profiles.update', $member), ['games_text' => 'x'.chr(0)."y\nMinecraft"])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasErrors(['games_text']);

    expect($profile->fresh()->games)->toBe(['Minecraft']);
});

it('still accepts tabs and newlines in a multiline bio', function () {
    // Tab, LF and CR are the controls a bio legitimately needs; the rule
    // allows exactly those and rejects everything else in Cc.
    $member = User::factory()->create();

    $this->actingAs($member)
        ->patch(route('profiles.update', $member), [
            'bio' => "Line one.\nLine two.\tTabbed.",
            'games' => ['Minecraft'],
        ])
        ->assertRedirect(route('profiles.show', $member))
        ->assertSessionHasNoErrors();

    expect($member->profile()->first()->bio)->toBe("Line one.\nLine two.\tTabbed.");
});

it('shows validation errors instead of saving control bytes via the Livewire form', function () {
    $member = User::factory()->create();
    $profile = Profile::factory()->for($member)->create([
        'bio' => 'Before',
        'games' => ['Minecraft'],
    ]);

    Livewire::actingAs($member)
        ->test(MemberProfile::class, [
            'member' => $member,
            'stats' => hardenedUnavailableStats($member->discord_id),
        ])
        ->call('edit')
        ->set('bio', 'a'.chr(0).'b')
        ->set('gamesText', 'x'.chr(0)."y\nMinecraft")
        ->call('save')
        ->assertSet('editing', true)
        ->assertHasErrors(['bio', 'gamesText']);

    expect($profile->fresh())
        ->bio->toBe('Before')
        ->games->toBe(['Minecraft']);
});
