/*
 * The application bundle. Deliberately empty.
 *
 * This file used to `import './bootstrap'`, which imported axios and hung it on
 * `window.axios`. Nothing ever read it: `grep -rn axios resources/ app/ routes/
 * tests/` matched only the import itself, and no page sends an XHR of its own —
 * every interaction on this site goes through Livewire, which ships its own
 * fetch layer and does not touch `window.axios`.
 *
 * Unused, it still cost 48 KB of the 49 KB bundle on EVERY page, and on the CI
 * budget profile (Slow 4G, ~184 KB/s) those bytes are ~267ms of transfer
 * competing with the font the largest text is waiting on. Deleting an import
 * nothing calls is the cheapest 267ms on the site. See TOG-53.
 *
 * If a page ever genuinely needs an HTTP client, use `fetch` — it is built in
 * and costs nothing — rather than restoring this dependency.
 */
