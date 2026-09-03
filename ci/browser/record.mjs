/**
 * Record a walkthrough of a page as a frame sequence — proof of the page
 * behaving, not just existing.
 *
 * A recording is the only artifact that shows what a still cannot: that the page
 * scrolls without shifting, and that the primary button takes focus with a
 * visible ring. Both are things this repo asserts in tests; this is what lets a
 * human confirm the assertion matches what they would actually see.
 *
 * Pipe the frames through gif.mjs to get something attachable to a card.
 *
 * Usage: node ci/browser/record.mjs <framesdir> <url>
 */
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { launch, VIEWPORTS, observeCls } from './launch.mjs';

const OUT = process.argv[2];
const URL_ARG = process.argv[3];

if (!OUT || !URL_ARG) {
  console.error('usage: node ci/browser/record.mjs <framesdir> <url>');
  process.exit(2);
}

mkdirSync(OUT, { recursive: true });

const browser = await launch();
const page = await browser.newPage();
await page.setViewport(VIEWPORTS.mobile);
await observeCls(page);
await page.goto(URL_ARG, { waitUntil: 'networkidle0', timeout: 60000 });

let frame = 0;
const snap = async () => {
  await page.screenshot({ path: join(OUT, `f${String(frame++).padStart(3, '0')}.png`) });
};

// Hold on the hero, so the first thing a viewer reads is the top of the page.
for (let i = 0; i < 6; i++) await snap();

// Keyboard path: Tab through the first few stops so the focus ring is on
// camera. This is the journey a keyboard user actually takes, and a missing
// :focus-visible ring is invisible in a still.
for (let i = 0; i < 3; i++) {
  await page.keyboard.press('Tab');
  await snap();
  await snap();
}

// Scroll the whole page in even steps. Every frame is a real paint, so a layout
// shift on scroll is visible here rather than merely absent from a number.
const height = await page.evaluate(() => document.body.scrollHeight);
const steps = 26;
for (let i = 1; i <= steps; i++) {
  await page.evaluate((y) => window.scrollTo(0, y), Math.round((height * i) / steps));
  await new Promise((r) => setTimeout(r, 60));
  await snap();
}

// Back to the top and rest, so a looping GIF ends where it began.
await page.evaluate(() => window.scrollTo(0, 0));
await new Promise((r) => setTimeout(r, 150));
for (let i = 0; i < 5; i++) await snap();

const cls = await page.evaluate(() => Number((window.__cls ?? 0).toFixed(4)));
await browser.close();
console.log(`${frame} frames -> ${OUT} (cls during walkthrough: ${cls})`);
