<?php

use Laravel\Dusk\Browser;

/*
 * The deferred-Livewire boot wait (TOG-7927): /events and /events/past boot
 * the runtime after window.load, so every `wire:click` control is unbound
 * until it arrives. The layout guard disables pre-boot buttons and stops
 * pre-boot link taps at capture, announcing through the
 * `livewire-boot-status` live region — so a member who taps too early hears
 * "still loading", not silence.
 *
 * Two halves, both required in every journey below:
 *
 * 1. Journeys that click a control wait for boot first. A `waitFor` on the
 *    control alone is not enough: the button renders (and is visible) before
 *    Livewire binds it, so the click can land in the unbound window and the
 *    test flakes on slow CI. Waiting on `initialRenderIsFinished` alone is
 *    not enough either: the flag is set on a `setTimeout` after
 *    `livewire:initialized` fires, while the guard lifts `disabled` exactly
 *    at `livewire:initialized` — after the component tree binds. Both
 *    conditions together pin the click to the bound side of the window.
 * 2. The pre-boot window itself is owned by the last test in
 *    EventsRsvpTest: it blocks the Livewire script at the network layer,
 *    proves the RSVP button is disabled and no answer is written, then
 *    unblocks and proves the control comes back to life and answers. The
 *    link half needs no browser proof: no deferred page server-renders an
 *    `a[wire:click]`, so a pre-boot link tap is unreachable on real markup —
 *    the capture-stop and the announced copy are pinned by the Feature
 *    tests. CDP request interception, not a paused sleep — a fixed delay is
 *    either a flake or dead time (docs/flake-policy.md).
 *
 * Shared here (see Support/ThrottleEnvelope.php) rather than defined
 * per-file: MobileClickPathTest drives the same RSVP click and carries the
 * same race, and a per-file copy drifts.
 */
function livewireBootedScript(): string
{
    return <<<'JS'
        return window.Livewire
            && window.Livewire.initialRenderIsFinished === true
            && !document.querySelector('[data-preboot-disabled]');
JS;
}

/** Wait until the deferred runtime has booted and the guard has lifted. */
function waitForLivewireBoot(Browser $browser): void
{
    $browser->waitUsing(20, 100, function () use ($browser) {
        $result = $browser->script(livewireBootedScript());

        return (bool) ($result[0] ?? false);
    }, 'Livewire never booted on the deferred page.');
}

/**
 * Read the pre-boot live region's current text. The node is screen-reader
 * only, so this reads `textContent` via script rather than going through
 * Dusk's visibility-aware text assertions.
 */
function bootStatusText(Browser $browser): string
{
    $result = $browser->script(
        'return (document.querySelector(\'[data-testid="livewire-boot-status"]\') || { textContent: \'\' }).textContent || \'\';'
    );

    return (string) ($result[0] ?? '');
}
