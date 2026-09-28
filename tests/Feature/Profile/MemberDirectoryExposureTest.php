<?php

use App\Enums\EventStatus;
use App\Livewire\MemberProfile;
use App\Models\Event;
use App\Models\Profile;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\Counts\CountsSource;
use App\Support\Counts\LiveCounts;
use App\Support\Profiles\MemberStats;
use Livewire\Livewire;

// TOG-5621: profiles are members-only. The two logged-out GET redirects are
// also pinned where the flows live (DiscordLoginTest for /profile,
// ProfileBackendTest for /members/{id}); this file pins everything around
// them: the member-adjacent JSON behind the same `auth` group, the Livewire
// entry point that does not pass through route middleware, the deleted PATCH
// write path (TOG-8440: a logged-out PATCH is a 405, never a write), and the
// actual requirement — no member data in any public page or its source.

/** A member whose every personal string is distinctive enough to grep for. */
function exposedMember(): User
{
    $member = User::factory()->create([
        'username' => 'zxq-directoryprobe',
        'display_name' => 'Zxq Directoryprobe',
    ]);
    Profile::factory()->for($member)->create([
        'bio' => 'Bio that must never reach a logged-out visitor 7f3a',
        'games' => ['Zxq Game One'],
        'timezone' => 'Europe/London',
    ]);

    return $member;
}

/** The personal strings that must never appear in a logged-out response. */
function memberSecrets(): array
{
    return [
        'Zxq Directoryprobe',
        'zxq-directoryprobe',
        'Bio that must never reach a logged-out visitor 7f3a',
        'Zxq Game One',
    ];
}

it('sends a logged-out visitor to login before showing any profile', function () {
    $member = User::factory()->create();

    $this->get(route('profile'))->assertRedirect(route('login'));
    $this->get(route('profiles.show', $member))->assertRedirect(route('login'));
});

it('has no logged-out profile write path left to smuggle through', function () {
    // TOG-8440: PATCH /members/{user} is deleted, so a logged-out PATCH on
    // the member URL never reaches `auth` — route matching 405s first — and
    // writes nothing.
    $member = User::factory()->create();

    $this->patch("/members/{$member->id}", ['bio' => 'Smuggled bio.'])
        ->assertMethodNotAllowed();

    expect($member->profile()->exists())->toBeFalse();
});

it('keeps the member-adjacent JSON behind login', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->get(route('events.json'))->assertRedirect(route('login'));
    $this->get(route('events.show', $event))->assertRedirect(route('login'));
    $this->put(route('events.rsvp.update', $event))->assertRedirect(route('login'));
    $this->delete(route('events.rsvp.destroy', $event))->assertRedirect(route('login'));
});

it('refuses a logged-out visitor at the Livewire member profile, not just the route', function () {
    // `livewire/update` is its own endpoint outside the `auth` group: the only
    // thing between a crafted guest payload and member data is the mount gate.
    // Livewire turns the mount's AuthorizationException into a 403 response
    // rather than rethrowing, so the denial is asserted, not the throw.
    $member = exposedMember();

    Livewire::test(MemberProfile::class, [
        'member' => $member,
        'stats' => MemberStats::unavailable($member->discord_id),
    ])->assertForbidden();
});

it('serves public pages to logged-out visitors with no member data in them', function () {
    $member = exposedMember();
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);
    Rsvp::factory()->for($event)->for($member)->create();

    // Aggregates, not people: the hero count and the rank ladder come from the
    // bot, so pin them unavailable and assert the page still says nothing about
    // any member.
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(LiveCounts::unavailable());
        $mock->shouldReceive('ranks')->andReturn([]);
    });

    foreach ([
        '/',
        route('events.index'),
        route('events.past'),
        route('join'),
        '/sitemap_index.xml',
        route('events.page', $event),
        route('events.rss'),
        route('events.ics', $event),
        route('events.feed'),
    ] as $url) {
        $response = $this->get($url)->assertOk();

        foreach (memberSecrets() as $secret) {
            $response->assertDontSee($secret, escape: false);
        }
    }
});

it('shows a logged-out visitor the RSVP count but never who is going', function () {
    $member = exposedMember();
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);
    Rsvp::factory()->for($event)->for($member)->create();

    // The aggregate count is the public fact ("1 going"); the name behind it
    // is the member fact, and it stays inside.
    $this->get(route('events.index'))
        ->assertOk()
        ->assertSee('1 going')
        ->assertDontSee('Zxq Directoryprobe', escape: false);
});
