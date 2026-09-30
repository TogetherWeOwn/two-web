<?php

use App\Models\Profile;
use App\Models\User;
use Laravel\Dusk\Browser;

it('keeps a readable, sized profile avatar fallback when the image is unavailable', function (?string $avatar) {
    $member = User::factory()->create([
        'display_name' => 'River',
        'username' => 'river-avatar-regression',
        'avatar' => $avatar,
    ]);
    Profile::factory()->for($member)->create([
        'bio' => 'Usually in co-op after work.',
        'games' => ['Minecraft'],
        'timezone' => 'Europe/London',
    ]);

    $this->browse(function (Browser $browser) use ($member, $avatar) {
        $browser->loginAs($member)
            ->visit('/profile');

        if ($avatar !== null) {
            // A real same-origin 404 drives the shipped handler; never dispatch
            // an error or change visibility ourselves. Match currentSrc so the
            // retina srcset request is covered too.
            $browser->waitUntil(<<<'JS'
                (() => {
                    const image = document.querySelector('[data-testid="profile-avatar-img"]');
                    return image && image.complete && image.naturalWidth === 0;
                })()
            JS)
                ->waitUntil(<<<'JS'
                    (() => {
                        const image = document.querySelector('[data-testid="profile-avatar-img"]');
                        return performance.getEntriesByName(image.currentSrc).some(entry =>
                            entry.initiatorType === 'img' && entry.responseStatus === 404);
                    })()
                JS)
                ->assertScript(<<<'JS'
                    new URL(document.querySelector('[data-testid="profile-avatar-img"]').currentSrc).origin === location.origin
                JS, true)
                ->assertPresent('[data-testid="profile-avatar-img"]')
                ->assertNotVisible('[data-testid="profile-avatar-img"]')
                ->assertScript('document.querySelector(\'[data-testid="profile-avatar-img"]\').hidden', true);
        } else {
            $browser->assertMissing('[data-testid="profile-avatar-img"]');
        }

        $browser->waitFor('[data-testid="profile-avatar-fallback"]')
            ->assertVisible('[data-testid="profile-avatar-fallback"]')
            ->assertScript('document.querySelector(\'[data-testid="profile-avatar-fallback"]\').innerText.trim()', 'RI');

        $box = $browser->script(<<<'JS'
            const fallback = document.querySelector('[data-testid="profile-avatar-fallback"]');
            const rect = fallback.getBoundingClientRect();
            return { width: rect.width, height: rect.height, display: getComputedStyle(fallback).display };
        JS)[0];

        expect($box['width'])->toBeGreaterThanOrEqual(95)->toBeLessThanOrEqual(97);
        expect($box['height'])->toBeGreaterThanOrEqual(95)->toBeLessThanOrEqual(97);
        expect($box['display'])->toBe('flex');

        $browser->assertSeeIn('h1', 'River')
            ->assertSee('@river-avatar-regression')
            ->assertSee('Usually in co-op after work.')
            ->assertSee('Minecraft')
            ->assertSee('Europe/London')
            ->assertVisible('[data-testid="profile-edit"]');
    });
})->with([
    'local missing image' => ['/__dusk_missing_profile_avatar_river__.png'],
    'null avatar' => [null],
]);
