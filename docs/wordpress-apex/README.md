# The WordPress apex hero — TOG-1163

`togetherweown.com` today is **not this application**. It is a WordPress.com
install running the Bricks theme (`wp-content/themes/bricks`, plus
`bricks-advanced-themer` and `automatic-css`), serving a one-section
coming-soon hero. The Laravel site in this repository replaces it at cutover
(`docs/dns.md`). Until then the apex is the only public web surface we have, and
the only action on it is the Discord button — the top of the entire funnel.

## The defect

Every hero rule on that page was inside one `@media (min-width: 1279px)` block.
Fourteen selectors: `.cs-hero`, `.cs-inner`, `.cs-badge`, `.cs-title`,
`.cs-tagline`, `.cs-discord`, `.cs-discord:hover`, `.cs-discord-icon`,
`.cs-note`, `.cs-features`, `.cs-feature`, `.cs-feature-emoji`,
`.cs-feature-label`, `.cs-copyright`.

Below 1279px **none of them applied**. The page fell back to the browser default
stylesheet: white background, black serif type, and the primary CTA as blue
underlined link text 16px tall. Not a degraded button — no button.

| width | CTA background | radius | tap target |
|---|---|---|---|
| 360 / 390 / 768 / 1278 | `rgba(0, 0, 0, 0)` | `0px` | **16px** |
| 1279 / 1280 | `rgb(88, 101, 242)` | `14px` | 54px |

`min-width: 1279px` also excludes a 1280px browser whose layout viewport is
reduced by a scrollbar, so this was never only a phone problem.

## The fix

`hero-fix.css` is the corrected contents of the inline
`<style id="bricks-frontend-inline-inline-css">` block:

1. The `@media (min-width: 1279px)` wrapper is **removed**, so all 14 rules apply
   unconditionally. Nothing inside it was desktop-specific; it styled the whole
   page.
2. One `@media (max-width: 1278px)` block is **added** after them, for the four
   values the desktop numbers get wrong on a phone: `min-height:100vh` → `auto`
   (so a short phone does not have to scroll to reach the button), hero side
   padding 24px → 20px, the CTA pinned to `min-height:44px` (WCAG 2.5.5), and a
   tighter feature-row gap.

Desktop is unchanged — verified, not assumed: every computed property measured
at 1279 and 1280 is identical before and after.

## Applying it

Nobody in this company has WordPress admin access (`/wp-admin` and `/wp-json`
both answer 403). It is a founder action, tracked as an `operator` card. The
block is Bricks' page-level custom CSS for the home page.

## Verifying it

```bash
node ci/browser/cta-breakpoints.mjs --url https://togetherweown.com/
```

Exit 0 means the CTA is a real button with a ≥44px tap target at 360, 390, 768,
1278, 1279 and 1280. Exit 1 means at least one width is still broken, and it
names them.

**If it prints `CHALLENGED`, that is not a verdict about the page.** Cloudflare
fronts the apex and answers repeated automated loads with its "Just a moment…"
interstitial, in which the hero is absent and the CTA measures transparent at
*every* width — including the ones that are fine. The script reports that state
separately and exits 2 rather than claiming a defect. When it happens, save the
document and measure the bytes:

```bash
curl -s https://togetherweown.com/ -o /tmp/live.html
node ci/browser/cta-breakpoints.mjs --file /tmp/live.html
```

`--file` reads only the HTML from disk; the seven external stylesheets still
load from the real origin, so the cascade is the visitor's. Only the document is
challenged by Cloudflare — static assets are not.
