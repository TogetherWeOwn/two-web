<?php

/*
 * The print handout for the shareable event page (TOG-6930), static half.
 * `/e/{event}` is a dark-ground screen design; paper is white and browsers
 * drop backgrounds in print, so the sheet re-inks the event card black on
 * white and drops everything that is a tap or navigation. The contract:
 *
 * 1. The `@media print` rules live in app.css, never two.css — that file is
 *    vendored from two-design and digest-pinned by VendoredTokensTest.
 * 2. Screen chrome carries `data-print="hide"`: back link, calendar buttons,
 *    attendee names, the RSVP/join block, prev/next cards, related events.
 *    Attendee names stay off a printout that can be left on a desk (TOG-5621).
 * 3. The event's own URL sits in a `hidden` footer with `data-print="url"`,
 *    revealed only in print — the on-screen address bar already shows it.
 *
 * Static assertions by choice: the defect this guards is the rules or the
 * markers going missing after a stylesheet or blade edit, and reading the
 * two files buys everything a render would — with no database. The
 * served-page half (the footer actually renders the canonical URL) lives
 * in tests/Feature/Events/EventPageTest.php, which needs Postgres.
 */

it('defines the print sheet in app.css, not the vendored two.css', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('@media print')
        ->and($css)->toContain("[data-print='hide']")
        ->and($css)->toContain('display: none')
        ->and($css)->toContain("[data-print='url']")
        ->and($css)->toContain('display: block')
        ->and($css)->toContain("[data-testid='event-page']");

    // The print medium is paper white and ink black — not design tokens.
    // Hex here is the medium asserting itself, not the system drifting.
    expect($css)->toContain('background-color: #fff')
        ->and($css)->toContain('color: #000');

    // two.css is byte-pinned to two-design upstream: a print rule there
    // fails VendoredTokensTest, so assert the absence directly.
    expect(file_get_contents(resource_path('css/two.css')))->not->toContain('@media print');
});

it('marks screen-only chrome as print-hidden on the event page', function () {
    // The markers are `data-print` attributes rather than `print:` Tailwind
    // variants, so the whole sheet is one commented block in app.css and a
    // reader can see the full contract without grepping class lists.
    $page = file_get_contents(resource_path('views/events/show.blade.php'));

    expect($page)->toContain('data-testid="event-calendar-links" data-print="hide"')
        ->and($page)->toContain('data-testid="event-attendees" data-print="hide"')
        ->and($page)->toContain('data-testid="event-pagination" data-print="hide"')
        ->and($page)->toContain('data-testid="event-related" data-print="hide"');
});

it('keeps the event URL as a print-only footer on the event page', function () {
    $page = file_get_contents(resource_path('views/events/show.blade.php'));

    // `hidden` on screen (the address bar already shows the URL), revealed
    // only by the print sheet; the footer prints the canonical shareable
    // URL, satisfying the "QR-or-URL" half of TOG-6930 without a new
    // dependency — the page already links this URL as its canonical.
    expect($page)->toContain('data-testid="event-print-url" data-print="url"')
        ->and($page)->toContain("route('events.page', \$event)");
});
