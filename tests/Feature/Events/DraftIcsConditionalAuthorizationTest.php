<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
    $this->draft = Event::factory()->create([
        'status' => EventStatus::Draft,
        'title' => 'Synthetic private draft calendar',
    ]);
    $this->url = route('events.ics', $this->draft);

    // Capture a real validator from an authorized download, not a guessed hash.
    $download = $this->actingAs($this->moderator)->get($this->url)->assertOk();
    $this->etag = $download->headers->get('ETag');

    expect($this->etag)->not->toBeNull()->not->toBe('')
        ->and($download->getContent())->toContain('BEGIN:VCALENDAR', $this->draft->title);
});

it('denies a matching draft ICS validator before conditional success for unauthorized viewers', function (bool $signedIn) {
    auth()->logout();

    if ($signedIn) {
        $this->actingAs(User::factory()->create(['is_moderator' => false]));
        $this->assertAuthenticated();
    } else {
        $this->assertGuest();
    }

    $response = $this->get($this->url, ['If-None-Match' => $this->etag])->assertForbidden();

    expect($response->headers->get('Content-Type'))->not->toStartWith('text/calendar')
        ->and($response->getContent())->not->toContain('BEGIN:VCALENDAR', $this->draft->title);
})->with([
    'guest' => false,
    'nonmoderator member' => true,
]);

it('still returns an empty 304 for a moderator with the unchanged draft ICS validator', function () {
    $this->actingAs($this->moderator)
        ->get($this->url, ['If-None-Match' => $this->etag])
        ->assertNoContent(304)
        ->assertHeader('ETag', $this->etag);
});
