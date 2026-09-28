// The pages CI measures. One source of truth — both the Lighthouse performance
// budget (ci/lighthouserc.cjs) and the WCAG 2.2 AA pass (ci/a11y.mjs) read this.
//
// ADD YOUR PAGE HERE WHEN YOU SHIP IT. A page that is not in this list has no
// performance budget and no accessibility check, and CI will happily go green on
// it. That is the single easiest way to quietly lose the budgets, so reviewers:
// a PR that adds a route and not a line here is not ready.
//
// `/discord` and `/join` are absent on purpose, not by oversight. Both answer with
// a redirect and no document, so there is nothing for Lighthouse to score or for
// axe to walk — adding them fails the budgets job rather than measuring anything.
// What they must do instead is pinned by tests/Feature/DiscordFunnelTest.php.
//
// AUTHENTICATED PAGES. This file used to say they "cannot be measured until the
// Dusk OAuth stub can hand this script a session cookie", and that note is why the
// moderator panel shipped in PR #218 with no accessibility check and no budget at
// all — a whole authenticated surface area outside the gate. `php artisan
// ci:session-cookie --moderator` now mints one, so a page can declare `auth: true`
// and be measured signed in. A page with `auth: true` and no cookie available is a
// hard failure in both consumers, never a silent skip: an unauthenticated request
// to /admin is a redirect, and scoring a redirect green is precisely the false
// comfort this file exists to prevent.

const BASE_URL = process.env.CI_BASE_URL || 'http://127.0.0.1:8000';

// Set by the budgets job from `artisan ci:session-cookie --moderator`.
const SESSION_COOKIE = process.env.CI_SESSION_COOKIE || '';

/** @type {{ path: string, name: string, auth?: boolean }[]} */
const allPages = [
  { path: '/', name: 'Homepage — the top of the join funnel' },
  // Public on purpose: the empty state is a pitch to join, so a signed-out visitor
  // arriving from a Discord link has to reach it.
  //
  // Worth measuring rather than assumed: most arrivals are a phone in the Discord
  // in-app browser, and the largest element is the first event card, so anything
  // that pushes the card down after paint spends the CLS budget.
  { path: '/events', name: 'Events calendar — list view, the default' },
  // Static leaf (TOG-5310): Route::view, no database, no Livewire.
  { path: '/about', name: 'About — static community introduction' },
  // Static leaf (TOG-5147): Route::view, no database, no Livewire.
  { path: '/rules', name: 'House rules — static community rules' },
  // Member surface (TOG-5634): signed-in profile with the Livewire edit form.
  // The budgets-job cookie mints a plain member session; /profile renders for
  // any signed-in member, moderator flag not needed. Lighthouse measures it
  // signed in — without the cookie this is the Discord handoff, a redirect the
  // landing check rightly refuses to score.
  {
    path: '/profile',
    name: 'Member profile — signed-in Livewire edit form',
    auth: true,
  },
  {
    path: '/admin',
    name: 'Moderator panel — the dashboard a moderator lands on',
    auth: true,
  },
  {
    path: '/admin/featured-contents',
    name: 'Moderator panel — the featured content a moderator edits',
    auth: true,
  },
  // /admin/bot-settings is deliberately absent here: the budgets job builds a
  // production-shaped app (APP_ENV=production), and AdminPanelProvider (TOG-3472)
  // gates the page out of production, so the route 404s there. It ships to
  // staging only until TOG-3093 covers the wider admin dashboard rollout.
];

/**
 * Refuse to measure an authenticated page signed out.
 *
 * This is a function called by the consumers rather than a throw in this file's
 * body, and the distinction is load-bearing: `ci/verify-pipeline.sh --lint`
 * require()s ci/lighthouserc.cjs — which requires this file — to read the budget
 * thresholds, and it runs in the `static` job where there is no session and none
 * is wanted. A throw at load time made the gate's own wiring check red on every
 * pull request, which is a worse failure than the one it was guarding against.
 *
 * Call it from anything that is about to actually load a page.
 */
const pages = allPages;

function assertMeasurable() {
  const authPages = pages.filter((page) => page.auth);

  if (authPages.length > 0 && !SESSION_COOKIE) {
    throw new Error(
      'CI_SESSION_COOKIE is empty but ' +
        authPages.map((page) => page.path).join(', ') +
        ' need a session. Run `php artisan ci:session-cookie --moderator` and export it. ' +
        'Measuring these signed out would score the Discord redirect, not the page.'
    );
  }
}

module.exports = {
  BASE_URL,
  SESSION_COOKIE,
  assertMeasurable,
  pages,
  urls: pages.map((page) => new URL(page.path, BASE_URL).toString()),
  // Lighthouse takes one extra-headers map for the whole run, so the cookie goes
  // on every request. Harmless on `/`: a session cookie a signed-out page ignores.
  extraHeaders: SESSION_COOKIE ? { Cookie: SESSION_COOKIE } : undefined,
};
