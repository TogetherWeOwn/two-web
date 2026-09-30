<?php

use App\Models\Event;
use Illuminate\Support\Facades\Cache;
use Laravel\Dusk\Browser;

test('live event search binds the URL and both clear controls recover after morphing', function () {
    Event::factory()->create([
        'title' => 'Helldivers & friends',
        'description' => 'A cooperative game night.',
    ]);
    Event::factory()->create([
        'title' => 'Valorant practice',
        'description' => 'A tactical team session.',
    ]);

    // Shared with the HTTP process: a healthy empty Discord read, not a failure.
    Cache::put('events.discord-upcoming', [], 600);

    try {
        $this->browse(function (Browser $browser) {
            $waitForQuery = function (?string $query) use ($browser): void {
                $browser->waitUsing(10, 100, function () use ($browser, $query) {
                    parse_str(parse_url($browser->driver->getCurrentURL(), PHP_URL_QUERY) ?? '', $parameters);

                    return $query === null
                        ? ! array_key_exists('q', $parameters)
                        : ($parameters['q'] ?? null) === $query;
                }, 'The browser URL did not reflect the live search.');
            };

            $assertRestoredList = function () use ($browser, $waitForQuery): void {
                // Clear briefly hides the content: wait for restored results, not
                // just the disappearing status or loading skeleton.
                $browser->waitForTextIn('[data-testid="events-list"]', 'Valorant practice');
                $waitForQuery(null);

                $browser->assertVisible('[data-testid="events-list"]')
                    ->assertSeeIn('[data-testid="events-list"]', 'Helldivers & friends')
                    ->assertSeeIn('[data-testid="events-list"]', 'Valorant practice')
                    ->assertCount('[data-testid="events-list"] [data-testid="event-card"]', 2)
                    ->assertInputValue('[data-testid="events-search"]', '')
                    ->assertQueryStringMissing('q')
                    ->assertAttribute('[data-testid="events-view-list"]', 'aria-pressed', 'true')
                    ->assertAttribute('[data-testid="events-view-calendar"]', 'aria-pressed', 'false')
                    ->assertMissing('[data-testid="events-calendar-grid"]')
                    ->assertMissing('[data-testid="events-search-status"]')
                    ->assertMissing('[data-testid="events-search-clear"]')
                    ->assertMissing('[data-testid="events-empty-search"]');
            };

            $browser->resize(1280, 900)
                ->visit('/events')
                ->waitForTextIn('[data-testid="events-list"]', 'Helldivers & friends')
                ->assertSeeIn('[data-testid="events-list"]', 'Valorant practice')
                ->assertCount('[data-testid="events-list"] [data-testid="event-card"]', 2);

            waitForLivewireBoot($browser);

            $browser->click('[data-testid="events-view-calendar"]')
                ->waitFor('[data-testid="events-calendar-grid"]')
                ->assertAttribute('[data-testid="events-view-calendar"]', 'aria-pressed', 'true')
                // Real input events drive the debounce, without Enter or blur.
                ->type('[data-testid="events-search"]', 'Helldivers & friends')
                ->waitForTextIn('[data-testid="events-search-status"]', 'Results for “Helldivers & friends”');
            $waitForQuery('Helldivers & friends');

            $browser->assertQueryStringHas('q', 'Helldivers & friends')
                ->assertAttribute('[data-testid="events-view-list"]', 'aria-pressed', 'true')
                ->assertMissing('[data-testid="events-calendar-grid"]')
                ->assertVisible('[data-testid="events-list"]')
                ->assertSeeIn('[data-testid="events-list"]', 'Helldivers & friends')
                ->assertDontSeeIn('[data-testid="events-list"]', 'Valorant practice')
                ->assertCount('[data-testid="events-list"] [data-testid="event-card"]', 1)
                ->click('[data-testid="events-search-clear"]');
            $assertRestoredList();

            // The input and clear buttons have now survived a Livewire morph.
            $browser->type('[data-testid="events-search"]', 'zzz-no-such-event-zzz')
                ->waitFor('[data-testid="events-empty-search"]')
                ->assertSeeIn('[data-testid="events-empty-search"]', 'Nothing matches that search.')
                ->assertMissing('[data-testid="event-card"]')
                ->assertMissing('[data-testid="events-empty-error"]')
                ->assertMissing('[data-testid="events-empty-never"]')
                ->assertMissing('[data-testid="events-empty-gap"]');
            $waitForQuery('zzz-no-such-event-zzz');

            $browser->assertQueryStringHas('q', 'zzz-no-such-event-zzz')
                ->click('[data-testid="events-search-clear-empty"]');
            $assertRestoredList();
        });
    } finally {
        Cache::forget('events.discord-upcoming');
    }
});
