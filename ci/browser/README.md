# ci/browser — a browser CLI for agents

Headless Chrome, driveable from the command line, for producing proof of work:
screenshots, walkthrough recordings, and the measurements a screenshot cannot
show.

## Why this exists

This company spent months believing no agent here could run a browser. Because
of that, every visual claim — layout, accessibility, performance — rested on
reading code rather than looking at the page. On 2026-09-03 that produced a
false "live on staging" report to the owner: a deploy job that skipped silently
was read as a green check, and there was no habit of opening the artifact.

The belief was wrong. Chrome was already in the image; it was missing 25 shared
libraries, and `apt-get` will download them unprivileged once its write paths
are redirected. No root, no image change.

The tooling used to prove that lived in a cache directory that dies with the
host. It is committed here so it is durable, reviewable, and available to every
agent on every checkout — not folklore that has to be rediscovered.

## Setup

```bash
./ci/browser/install-chrome-deps.sh    # ~2 min, ~280MB, once per host
source ci/browser/env.sh               # per shell
npm i --no-save --no-package-lock puppeteer-core@23.11.1 gifenc@1.0.3 sharp@0.33.5
```

`env.sh` is **generated, not committed** — the prefix path differs per host, and
a stale committed one would point at a directory that does not exist. On GitHub
Actions the installer detects that the libraries are already present and exits
early. The scripts also fall back to the conventional locations, so they work
without sourcing anything.

## Scripts

| script | what it does |
|---|---|
| `install-chrome-deps.sh` | builds the dependency prefix; idempotent; writes `env.sh` |
| `launch.mjs` | the one place that knows how to start Chrome here; `VIEWPORTS`, `observeCls`, `preflight` |
| `capture.mjs` | full-page shots at phone + desktop, plus `report.json` (CLS, paints, overflow, CTA count, headings, landmarks) |
| `record.mjs` | frame-sequence walkthrough: hero hold, Tab focus path, full scroll |
| `gif.mjs` | stitches frames into an animated GIF, and verifies it is actually animated |
| `selftest.mjs` | tests for the prover — see below |

```bash
node ci/browser/capture.mjs /tmp/shots http://127.0.0.1:8000 / /join
node ci/browser/record.mjs  /tmp/frames http://127.0.0.1:8000/
node ci/browser/gif.mjs     /tmp/frames /tmp/walkthrough.gif 300 110
```

## The selftest, and why it is not optional

```bash
node ci/browser/selftest.mjs    # ~20s, no network, no PHP, no app
```

These scripts exist to produce evidence a human will trust without re-deriving
it. That makes a *silently wrong* capture the worst possible outcome — worse
than no capture, because it is indistinguishable from a real one and it gets
pasted onto a card as proof.

Every case in the selftest is a false success this tooling has actually
produced, and each is mutation-verified — the mutation is listed so you can
re-run it:

| Trap | Mutation that must turn it red |
|---|---|
| A "mobile" shot that is a **crop of the desktop layout**. `--window-size` leaves `deviceScaleFactor` and `isMobile` unset. | drop `deviceScaleFactor`/`isMobile` from `VIEWPORTS.mobile` → width 720→360 |
| **CLS of 0** from an observer attached after `goto`. The entries you care about have already fired. | `evaluateOnNewDocument` → `evaluate` → cls 0.303→0 |
| A GIF reported as **"43 frames"** that is one 27907px-tall image. sharp's multi-page path ignores `pageHeight` here. | encode `files.slice(0, 1)` → read-back reports 1, exit 1 |
| A Chrome that passes `--version` and **cannot render**. `--version` never loads the graphics stack. | — asserted by rendering a real DOM |

A real 360px phone capture is **720px wide on disk**, because
`deviceScaleFactor` is 2. That width is how a reviewer tells a genuine mobile
capture from a cropped desktop one without trusting the caption.

## Other traps this cost real time

- **`t64` package names.** Debian 13's 64-bit-`time_t` transition left both names
  in the archive. `libcups2` still resolves — as an *empty transitional shim*.
  Seed the bare name and apt exits 0 having unpacked no `.so`; the failure only
  surfaces later as Chrome refusing to start. Six libraries hit this.
  **Trust the `ldd` count, never apt's exit code.**
- **`dbus` connection errors are benign.** There is no system bus here. Chrome
  logs the failure twice and renders fine.
- **`npm install --no-save` prunes earlier `--no-save` packages.** Install
  everything you need in one command.
- **`cmd | tail` reports `tail`'s exit code.** Redirect to a file and check `$?`,
  or the selftest looks green while failing.

## Attaching proof to a card

```bash
curl -X POST "$PAPERCLIP_API_URL/companies/$PAPERCLIP_COMPANY_ID/issues/$ID/attachments" \
  -H "Authorization: Bearer $PAPERCLIP_API_KEY" -F "file=@shot.png"
```

Then **open what you uploaded** and check it against what was asked.
