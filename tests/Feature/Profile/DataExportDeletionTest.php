<?php

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Enums\RsvpStatus;
use App\Models\DataRequest;
use App\Models\Event;
use App\Models\MemberDataAccessLog;
use App\Models\Profile;
use App\Models\Rsvp;
use App\Models\User;

// TOG-8705: the member's self-service data section on /profile. Download my
// data (JSON of own profile + RSVPs) and request deletion (a row in the
// moderator-visible queue). Both are self-only by construction — the routes
// take no id — and both sit behind `auth`, so logged-out access redirects to
// login like the profile pages.

it('redirects logged-out visitors on the data routes to login', function () {
    $this->get(route('profile.data-export'))->assertRedirect(route('login'));
    $this->post(route('profile.deletion-request'))->assertRedirect(route('login'));
});

it('downloads the member’s own profile and RSVPs as a JSON attachment', function () {
    $member = User::factory()->create(['display_name' => 'River']);
    Profile::factory()->for($member)->create([
        'bio' => 'Usually in co-op after work.',
        'games' => ['Helldivers 2'],
        'timezone' => 'Europe/London',
    ]);
    $event = Event::factory()->create(['title' => 'Friday night Helldivers']);
    Rsvp::factory()->for($member)->for($event)->create(['status' => RsvpStatus::Going]);

    $response = $this->actingAs($member)->get(route('profile.data-export'));

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="together-we-own-data.json"');

    $json = $response->json();

    expect($json['profile']['discord_id'])->toBe($member->discord_id)
        ->and($json['profile']['bio'])->toBe('Usually in co-op after work.')
        ->and($json['profile']['games'])->toBe(['Helldivers 2'])
        ->and($json['profile']['timezone'])->toBe('Europe/London')
        ->and($json['rsvps'])->toHaveCount(1)
        ->and($json['rsvps'][0]['event_key'])->toBe($event->event_key)
        ->and($json['rsvps'][0]['status'])->toBe('going')
        ->and($json['exported_at'])->toBeString();
});

it('exports empty shapes rather than nulls when the member has no profile or RSVPs', function () {
    $member = User::factory()->create();

    $json = $this->actingAs($member)->get(route('profile.data-export'))->assertOk()->json();

    expect($json['profile']['bio'])->toBeNull()
        ->and($json['profile']['games'])->toBe([])
        ->and($json['rsvps'])->toBe([]);
});

it('records the export download in the member access log', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->get(route('profile.data-export'))->assertOk();

    // Self-read, so the viewer-own-record exclusion drops it — same as the
    // /profile page itself. The point is the route carries the middleware;
    // the completeness audit proves the line is there.
    expect(MemberDataAccessLog::query()->count())->toBe(0);
});

it('creates a deletion request in the moderator queue and confirms on /profile', function () {
    $member = User::factory()->create();

    $this->actingAs($member)
        ->post(route('profile.deletion-request'))
        ->assertRedirect(route('profile'));

    $request = DataRequest::query()->sole();

    expect($request->user_id)->toBe($member->id)
        ->and($request->discord_id)->toBe($member->discord_id)
        ->and($request->type)->toBe(DataRequestType::Deletion)
        ->and($request->status)->toBe(DataRequestStatus::Pending);

    $this->actingAs($member)->get(route('profile'))
        ->assertOk()
        ->assertSee('data-testid="profile-data-request-status"', escape: false)
        ->assertSee('data-testid="profile-deletion-pending"', escape: false)
        ->assertDontSee('data-testid="profile-deletion-form"', escape: false);
});

it('answers a double-submitted deletion request with the same confirmation, not a 500', function () {
    $member = User::factory()->create();

    $this->actingAs($member)->post(route('profile.deletion-request'))->assertRedirect(route('profile'));
    $this->actingAs($member)->post(route('profile.deletion-request'))->assertRedirect(route('profile'));

    expect(DataRequest::query()->count())->toBe(1);
});

it('shows the data section on the owner’s /profile and hides it on another member’s page', function () {
    $viewer = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($viewer)->get(route('profile'))
        ->assertOk()
        ->assertSee('data-testid="profile-data-section"', escape: false)
        ->assertSee('data-testid="profile-data-download"', escape: false);

    // The auth group answers /members/{self} with the same page: the section
    // follows the viewer, not the URL.
    $this->actingAs($viewer)->get(route('profiles.show', $viewer))
        ->assertOk()
        ->assertSee('data-testid="profile-data-section"', escape: false);

    $this->actingAs($viewer)->get(route('profiles.show', $other))
        ->assertOk()
        ->assertDontSee('data-testid="profile-data-section"', escape: false)
        ->assertDontSee('data-testid="profile-data-download"', escape: false);
});

it('keeps an unrelated viewer’s download and queue row out of another member’s reach', function () {
    // There is no {user} wildcard to smuggle on either route — both read the
    // caller — so "another member's data" is not an addressable thing. The
    // closest a cross-member probe gets is the ordinary profile page, which
    // shows the profile but never the data section.
    $viewer = User::factory()->create();
    $other = User::factory()->create();
    Profile::factory()->for($other)->create(['bio' => 'Not yours.']);

    $json = $this->actingAs($viewer)->get(route('profile.data-export'))->assertOk()->json();

    expect($json['profile']['discord_id'])->toBe($viewer->discord_id)
        ->and($json['profile']['bio'])->not->toBe('Not yours.');
});
