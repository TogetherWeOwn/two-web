<?php

use App\Models\Event;
use Carbon\CarbonImmutable;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Support\Facades\Cache;
use Laravel\Dusk\Browser;

test('the compiled calendar loader respects reduced motion during a month change', function () {
    expect(Event::query()->count())->toBe(0);
    // Shared with the HTTP process: a healthy empty bot read, never Discord.
    Cache::put('events.discord-upcoming', [], 600);

    try {
        $this->browse(function (Browser $browser) {
            $cdp = new ChromeDevToolsDriver($browser->driver);
            $cdp->execute('Emulation.setEmulatedMedia', [
                'features' => [['name' => 'prefers-reduced-motion', 'value' => 'reduce']],
            ]);

            try {
                $browser->resize(1280, 900)
                    ->visit('/events')
                    ->waitFor('[data-testid="events-empty-never"]');

                waitForLivewireBoot($browser);

                $browser->click('[data-testid="events-view-calendar"]')
                    ->waitFor('[data-testid="events-calendar-grid"]')
                    ->assertNotVisible('[data-testid="events-loading"]')
                    ->assertVisible('[data-testid="events-content"]');

                $month = trim($browser->text('[data-testid="calendar-month"]'));
                $nextMonth = CarbonImmutable::createFromFormat('!F Y', $month)->addMonth()->format('F Y');

                // Hold only the real nextMonth response, after it arrives. No
                // server delay, synthetic response, sleep or polling loop.
                $browser->script(<<<'JS'
                    const originalFetch = window.fetch;
                    let held, failed, release;
                    const pending = new Promise((resolve, reject) => { held = resolve; failed = reject; });
                    const gate = window.calendarLoaderGate = {
                        pending, count: 0, release: () => release?.(),
                        restore: () => { release?.(); window.fetch = originalFetch; }
                    };
                    window.fetch = async function (input, init) {
                        const url = new URL(typeof input === 'string' ? input : input.url, location.href);
                        const body = url.pathname === '/livewire/update' && init?.body
                            ? JSON.parse(init.body) : null;
                        const monthChange = body?.components?.some(component =>
                            component.calls?.some(call => call.method === 'nextMonth'));
                        if (!monthChange) return originalFetch.call(this, input, init);
                        gate.count++;
                        try {
                            const response = await originalFetch.call(this, input, init);
                            gate.status = response.status;
                            await new Promise(resolve => { release = resolve; held(); });
                            return response;
                        } catch (error) {
                            failed(error);
                            throw error;
                        }
                    };
                JS);

                $browser->click('button[aria-label="Next month"]');

                $pending = $browser->driver->executeAsyncScript(<<<'JS'
                    const done = arguments[arguments.length - 1];
                    window.calendarLoaderGate.pending.then(() => {
                        requestAnimationFrame(() => {
                            const loader = document.querySelector('[data-testid="events-loading"]');
                            const content = document.querySelector('[data-testid="events-content"]');
                            const status = document.querySelector('[data-testid="calendar-month-status"]');
                            const exposed = element => {
                                for (let node = element; node; node = node.parentElement) {
                                    const style = getComputedStyle(node);
                                    if (node.hidden || node.getAttribute('aria-hidden') === 'true'
                                        || style.display === 'none' || style.visibility === 'hidden') return false;
                                }
                                return true;
                            };
                            done({
                                reducedMotion: matchMedia('(prefers-reduced-motion: reduce)').matches,
                                count: window.calendarLoaderGate.count,
                                responseStatus: window.calendarLoaderGate.status,
                                loaderDisplay: getComputedStyle(loader).display,
                                contentDisplay: getComputedStyle(content).display,
                                month: document.querySelector('[data-testid="calendar-month"]').textContent.trim(),
                                statusText: status.textContent.trim(),
                                statusRole: status.getAttribute('role'),
                                statusOutsideContent: !content.contains(status),
                                statusExposed: exposed(status),
                                animations: Array.from(loader.querySelectorAll('.animate-pulse'), element => {
                                    const style = getComputedStyle(element);
                                    return {
                                        name: style.animationName,
                                        durations: style.animationDuration.split(',').map(value => parseFloat(value)),
                                        iterations: style.animationIterationCount
                                    };
                                })
                            });
                        });
                    }, error => done({error: String(error)}));
                JS);

                $browser->assertVisible('[data-testid="events-loading"]')
                    ->assertNotVisible('[data-testid="events-content"]');

                expect($pending)->not->toHaveKey('error');
                expect($pending['reducedMotion'])->toBeTrue();
                expect($pending['count'])->toBe(1);
                expect($pending['responseStatus'])->toBe(200);
                expect($pending['loaderDisplay'])->toBe('flex');
                expect($pending['contentDisplay'])->toBe('none');
                expect($pending['month'])->toBe($month);
                expect($pending['statusText'])->toBe($month);
                expect($pending['statusRole'])->toBe('status');
                expect($pending['statusOutsideContent'])->toBeTrue();
                expect($pending['statusExposed'])->toBeTrue();
                expect($pending['animations'])->not->toBeEmpty();

                foreach ($pending['animations'] as $animation) {
                    // Positive duration/name prevents missing compiled CSS from
                    // passing on the browser defaults (none / 0s / 1).
                    expect($animation['name'])->not->toBe('none');
                    expect($animation['iterations'])->toBe('1');
                    foreach ($animation['durations'] as $duration) {
                        expect($duration)->toBeGreaterThan(0)->toBeLessThanOrEqual(0.00002);
                    }
                }

                // Observe the morph instead of polling; register before release
                // so even a fast response cannot slip between the two steps.
                $settled = $browser->driver->executeAsyncScript(<<<'JS'
                    const expectedMonth = arguments[0];
                    const done = arguments[arguments.length - 1];
                    const check = () => {
                        const loader = document.querySelector('[data-testid="events-loading"]');
                        const content = document.querySelector('[data-testid="events-content"]');
                        const label = document.querySelector('[data-testid="calendar-month"]');
                        const status = document.querySelector('[data-testid="calendar-month-status"]');
                        if (label?.textContent.trim() !== expectedMonth
                            || status?.textContent.trim() !== expectedMonth
                            || getComputedStyle(loader).display !== 'none'
                            || getComputedStyle(content).display !== 'block') return;
                        observer.disconnect();
                        done({month: label.textContent.trim(), status: status.textContent.trim(),
                            count: window.calendarLoaderGate.count});
                    };
                    const observer = new MutationObserver(check);
                    observer.observe(document.body, {subtree: true, childList: true, attributes: true, characterData: true});
                    window.calendarLoaderGate.release();
                    check();
                JS, [$nextMonth]);

                expect($settled)->toBe(['month' => $nextMonth, 'status' => $nextMonth, 'count' => 1]);
                $browser->assertSeeIn('[data-testid="calendar-month"]', $nextMonth)
                    ->assertNotVisible('[data-testid="events-loading"]')
                    ->assertVisible('[data-testid="events-content"]');
            } finally {
                $browser->script('window.calendarLoaderGate?.restore(); delete window.calendarLoaderGate;');
                $cdp->execute('Emulation.setEmulatedMedia', ['features' => []]);
            }
        });
    } finally {
        Cache::forget('events.discord-upcoming');
    }
});
