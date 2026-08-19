// The pages CI measures. One source of truth — both the Lighthouse performance
// budget (ci/lighthouserc.cjs) and the WCAG 2.2 AA pass (ci/a11y.mjs) read this.
//
// ADD YOUR PAGE HERE WHEN YOU SHIP IT. A page that is not in this list has no
// performance budget and no accessibility check, and CI will happily go green on
// it. That is the single easiest way to quietly lose the budgets, so reviewers:
// a PR that adds a route and not a line here is not ready.
//
// Authenticated pages (/profile) cannot be measured until the Dusk OAuth stub from
// TWO-34 can hand this script a session cookie. Tracked there, not forgotten here.

const BASE_URL = process.env.CI_BASE_URL || 'http://127.0.0.1:8000';

/** @type {{ path: string, name: string }[]} */
const pages = [
  { path: '/', name: 'Homepage — the top of the join funnel' },
];

module.exports = {
  BASE_URL,
  pages,
  urls: pages.map((page) => new URL(page.path, BASE_URL).toString()),
};
