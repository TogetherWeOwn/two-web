<?php

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

test('a blocked Discord widget leaves the join fallback usable at 360px', function () {
    config()->set('services.discord.guild_id', '900000000000000001');
    config()->set('services.discord.invite_url', 'https://discord.gg/testinvite');

    // Render the real route with test-process config, not the separately running
    // server's guild. Serve its HTML locally so built assets keep their origin.
    $html = $this->get('/join')->assertOk()->getContent();
    // A restrictive embed policy refuses frame navigation in every renderer,
    // including cross-origin child targets that CDP network blocking can miss.
    // Only this disposable fixture has the policy; application markup is intact.
    $html = str_replace('<head>', '<head><meta http-equiv="Content-Security-Policy" content="frame-src \'none\'">', $html, $heads);
    expect($heads)->toBe(1);
    $fixture = 'dusk-join-blocked-widget-'.Str::uuid().'.html';
    $path = public_path($fixture);
    file_put_contents($path, $html);

    try {
        $this->browse(function (Browser $browser) use ($fixture) {
            $cdp = new ChromeDevToolsDriver($browser->driver);
            $cdp->execute('Network.enable');
            $cdp->execute('Network.setBlockedURLs', ['urls' => [
                '*discord.com*', '*discord.gg*', '*discordapp.com*', '*discordapp.net*',
            ]]);
            $observer = $cdp->execute('Page.addScriptToEvaluateOnNewDocument', ['source' => <<<'JS'
                window.joinBlockedFrames = [];
                window.addEventListener('securitypolicyviolation', (event) => {
                    if (event.effectiveDirective === 'frame-src') {
                        window.joinBlockedFrames.push(event.blockedURI);
                    }
                });
            JS]);
            $cdp->execute('Emulation.setDeviceMetricsOverride', [
                'width' => 360,
                'height' => 780,
                'deviceScaleFactor' => 2,
                'mobile' => true,
            ]);

            try {
                $browser->visit('/'.$fixture)
                    ->assertAttribute('[data-testid="join-widget"]', 'src', 'https://discord.com/widget?id=900000000000000001&theme=dark')
                    ->scrollIntoView('[data-testid="join-widget"]');

                // loading=lazy must actually attempt the frame. A missing or
                // never-loaded iframe is not evidence of browser-time refusal.
                $browser->waitUsing(10, 100, function () use ($browser) {
                    return (bool) ($browser->script("return window.joinBlockedFrames.includes('https://discord.com');")[0] ?? false);
                }, 'Chrome never refused the synthetic Discord widget request.');

                $browser->scrollIntoView('[data-testid="join-expect"]')
                    ->assertVisible('[data-testid="join-expect"]')
                    ->assertSeeIn('[data-testid="join-expect"]', __('join.expect_heading'))
                    ->assertVisible('[data-testid="join-fallback-invite"]')
                    ->assertAttribute('[data-testid="join-fallback-invite"]', 'href', 'https://discord.gg/testinvite')
                    ->assertScript('return window.innerWidth;', 360)
                    ->assertScript('return document.documentElement.scrollWidth <= window.innerWidth + 1;');

                foreach (__('join.expect') as $index => $step) {
                    $browser->assertVisible('[data-testid="join-expect"] li:nth-child('.($index + 1).')')
                        ->assertSeeIn('[data-testid="join-expect"] li:nth-child('.($index + 1).')', $step);
                }

                // Observe activation without ever leaving the local fixture.
                // CDP blocking remains in place as a second external-call guard.
                $browser->script(<<<'JS'
                    window.joinFallbackDestination = null;
                    document.querySelector('[data-testid="join-fallback-invite"]').addEventListener('click', (event) => {
                        event.preventDefault();
                        window.joinFallbackDestination = event.currentTarget.href;
                    });
                    document.activeElement.blur();
                    window.scrollTo(0, 0);
                JS);

                // Real Tab traversal, not focus() on the target: a tabindex=-1
                // regression must fail even if the anchor remains visible.
                $reached = false;
                for ($tab = 0; $tab < 30; $tab++) {
                    $browser->driver->switchTo()->activeElement()->sendKeys(WebDriverKeys::TAB);
                    $reached = (bool) ($browser->script('return document.activeElement.matches(\'[data-testid="join-fallback-invite"]\');')[0] ?? false);
                    if ($reached) {
                        break;
                    }
                }

                expect($reached)->toBeTrue('The fallback invite is not keyboard reachable.');
                $browser->assertFocused('[data-testid="join-fallback-invite"]')
                    ->assertVisible('[data-testid="join-fallback-invite"]');
                $browser->driver->switchTo()->activeElement()->sendKeys(WebDriverKeys::ENTER);
                $browser->assertScript('return window.joinFallbackDestination;', 'https://discord.gg/testinvite')
                    ->assertPathIs('/'.$fixture)
                    ->assertScript('return document.documentElement.scrollWidth <= window.innerWidth + 1;');
            } finally {
                // Preserve the refusal viewport on pass or failure before the
                // reused browser's network and emulation state is restored.
                $browser->screenshot('join-blocked-widget-360');
                $cdp->execute('Page.removeScriptToEvaluateOnNewDocument', ['identifier' => $observer['identifier']]);
                $cdp->execute('Network.setBlockedURLs', ['urls' => []]);
                $cdp->execute('Emulation.clearDeviceMetricsOverride');
            }
        });
    } finally {
        unlink($path);
    }
});
