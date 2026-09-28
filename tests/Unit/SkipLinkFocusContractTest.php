<?php

/*
 * The public focus pass (TOG-6932), static half. Six public pages — home,
 * /events, the event share page, /join, /about, /rules — share one layout,
 * so the contract is pinned at the layout level plus the one page-level
 * exception:
 *
 * 1. #main is a programmatic focus target (tabindex="-1") so the skip link
 *    moves focus in Chrome/Safari, not just the scroll position.
 * 2. The light-scheme focus override exists in app.css: the vendored
 *    near-white ring is 1.05:1 on ledger paper (invisible), so ledger/taste
 *    pages get their own ink ring plus an opaque skip-link pill. Dark pages
 *    keep the vendored ring untouched.
 * 3. The events calendar scroll region carries role="region" so its
 *    aria-label is exposed without trapping keyboard tab order.
 *
 * Static assertions by choice: the defect this guards is the CSS/HTML being
 * absent after a layout or stylesheet edit, and reading the three files buys
 * everything a render would — with no database. The served-page half
 * (skip link present on all six pages) lives in
 * tests/Feature/SkipLinkFocusTest.php, which needs Postgres.
 */

it('makes #main a programmatic focus target for the skip link', function () {
    // Chrome/Safari scroll to the anchor but leave focus on <body>; without
    // tabindex="-1" a keyboard user who skips then tabs starts over at the
    // top of the page. The layout is the single place this can regress.
    $layout = file_get_contents(resource_path('views/components/layouts/app.blade.php'));

    expect($layout)->toContain('<main id="main" tabindex="-1"');
});

it('overrides the focus ring on the light schemes in app.css, not two.css', function () {
    // The ring override lives in app.css because two.css is vendored from
    // two-design and digest-pinned by VendoredTokensTest — editing it there
    // fails CI. The skip-link pill must be opaque: revealed bare text on
    // ledger paper is a 1.4:1 smear.
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('.bg-ledger-paper')
        ->and($css)->toContain('.bg-taste-paper')
        ->and($css)->toContain(':focus-visible')
        ->and($css)->toContain('outline-color: var(--color-ledger-ink)')
        ->and($css)->toContain('outline-color: var(--color-taste-ink)')
        ->and($css)->toContain("a[href='#main']:focus-visible")
        ->and($css)->toContain('background-color: var(--color-canvas)')
        // The pill must stay scoped to the light schemes: unscoped, its
        // canvas fill equals the dark page ground and its ledger outline
        // falls to ~1.1:1 on canvas, replacing the vendored 17.5:1 ring.
        ->and($css)->toContain(":where(.bg-ledger-paper, .bg-taste-paper) a[href='#main']:focus-visible")
        ->and($css)->toContain('position: relative');
});

it('leaves the vendored dark focus ring untouched', function () {
    // The base rule in two.css is correct for the dark system (17.5:1 on
    // canvas). The app.css layer only narrows the selector to the light
    // schemes, so a dark-ground page keeps the vendored ring.
    expect(file_get_contents(resource_path('css/two.css')))
        ->toContain(':where(a, button, input, select, textarea, summary, [tabindex]):focus-visible');
});

it('labels the events calendar scroll region without trapping keyboard tab order', function () {
    $calendar = file_get_contents(resource_path('views/livewire/events-calendar.blade.php'));

    expect($calendar)->toContain('role="region"')
        ->and($calendar)->toContain('data-testid="events-calendar-scroll"');
});
