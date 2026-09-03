<?php

use App\Models\Event;
use App\Models\FeaturedContent;
use App\Models\User;

// One block per resource, same three questions each time: guest out, member
// 403, moderator in. The panel-level gate is proven in PanelAccessTest; these
// exist because a resource registered outside the panel's middleware — or one
// whose policy Filament fails to find — passes that test and still leaks. Every
// URL a resource adds must answer for itself.

$resources = [
    'events' => [
        'index' => '/admin/events',
        'create' => '/admin/events/create',
    ],
    'featured content' => [
        'index' => '/admin/featured-contents',
        'create' => '/admin/featured-contents/create',
    ],
];

foreach ($resources as $name => $urls) {
    foreach ($urls as $page => $url) {
        it("redirects a guest from the {$name} {$page} page to login", function () use ($url) {
            $this->get($url)->assertRedirect(route('login'));
        });

        it("answers a plain member with 403 on the {$name} {$page} page", function () use ($url) {
            $member = User::factory()->create(['is_moderator' => false]);

            $this->actingAs($member)->get($url)->assertForbidden();
        });

        it("lets a moderator load the {$name} {$page} page", function () use ($url) {
            $moderator = User::factory()->create(['is_moderator' => true]);

            $this->actingAs($moderator)->get($url)->assertOk();
        });
    }
}

it('lets a moderator open the edit page for an event', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $event = Event::factory()->create();

    $this->actingAs($moderator)->get("/admin/events/{$event->id}/edit")->assertOk();
});

it('answers a plain member with 403 on the event edit page', function () {
    $member = User::factory()->create(['is_moderator' => false]);
    $event = Event::factory()->create();

    $this->actingAs($member)->get("/admin/events/{$event->id}/edit")->assertForbidden();
});

it('lets a moderator open the edit page for featured content', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $row = FeaturedContent::factory()->create();

    $this->actingAs($moderator)->get("/admin/featured-contents/{$row->id}/edit")->assertOk();
});

it('answers a plain member with 403 on the featured content edit page', function () {
    $member = User::factory()->create(['is_moderator' => false]);
    $row = FeaturedContent::factory()->create();

    $this->actingAs($member)->get("/admin/featured-contents/{$row->id}/edit")->assertForbidden();
});
