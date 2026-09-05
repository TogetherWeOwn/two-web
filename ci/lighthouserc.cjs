// Performance budget. These numbers came from the CEO and are enforced as build
// failures, not warnings:
//
//     LCP < 2.0s   on a mid-range phone profile
//     CLS < 0.1
//
// Do not edit a threshold to make a build go green. Lowering a budget is a CEO
// decision made in writing on the issue, and then reflected here in its own commit
// that says so. A budget quietly relaxed inside a feature PR is the failure mode
// this whole file exists to prevent.

const { urls, extraHeaders, assertMeasurable } = require('./pages.cjs');

module.exports = {
  ci: {
    collect: {
      // A getter, not `url: urls`, and the reason is not cosmetic. This file is
      // require()d by two callers with opposite needs: lhci, which is about to
      // open these pages and must refuse to do it signed out, and
      // `ci/verify-pipeline.sh --lint`, which only reads the thresholds below and
      // runs in `static` where there is no session. Checking at load time made the
      // gate's own wiring check red on every PR; checking here fires for whoever
      // actually asks for the list, which is only ever the tool that loads it.
      get url() {
        assertMeasurable();

        return urls;
      },

      // The moderator panel is behind auth, so without this Lighthouse would
      // score the Discord redirect instead — a tiny, fast page that passes every
      // budget while /admin goes unmeasured (TOG-54). The cookie comes from
      // `artisan ci:session-cookie --moderator`; ci/pages.cjs throws outright if
      // an authenticated page is listed and the cookie is absent.
      extraHeaders,

      // Lighthouse's default preset is already the mid-range phone: a Moto G Power
      // class device, 4x CPU slowdown, simulated Slow 4G. We do not loosen it — the
      // TWO audience plays on phones and joins from bed.
      settings: {
        formFactor: 'mobile',
        screenEmulation: {
          mobile: true,
          width: 412,
          height: 823,
          deviceScaleFactor: 1.75,
          disabled: false,
        },
        // Spelled out rather than left to the default so a Lighthouse upgrade that
        // changes its defaults shows up as a diff here, not as a budget that
        // silently got easier.
        throttlingMethod: 'simulate',
        throttling: {
          rttMs: 150,
          throughputKbps: 1638.4,
          cpuSlowdownMultiplier: 4,
          requestLatencyMs: 562.5,
          downloadThroughputKbps: 1474.56,
          uploadThroughputKbps: 675,
        },
        // Only the categories we actually gate on. Collecting the rest triples the
        // runtime for numbers nobody reads.
        onlyCategories: ['performance'],
        // Real accessibility enforcement is axe in ci/a11y.mjs. Lighthouse's a11y
        // score is a subset and does not cover WCAG 2.2 at all — passing it is not
        // the same as passing AA, and treating it as such is how teams ship an
        // inaccessible site with a green badge.
        skipAudits: ['uses-http2', 'canonical'],
      },

      // A single LCP sample on a shared GitHub runner is noise — we measured the
      // spread before choosing this. Three runs, median reported. This is the
      // sanctioned answer to variance; retrying a failed run until it passes is
      // not (docs/flake-policy.md).
      numberOfRuns: 3,
    },

    assert: {
      assertions: {
        'largest-contentful-paint': ['error', { maxNumericValue: 2000, aggregationMethod: 'median' }],
        'cumulative-layout-shift': ['error', { maxNumericValue: 0.1, aggregationMethod: 'median' }],

        // The LCP budget above does not cover a slow server, and the reason is not
        // obvious enough to leave unwritten (TWO-93).
        //
        // `simulate` does not report the timings Chrome observed — Lantern rebuilds
        // them from the trace. It models *one* server response time per origin: the
        // median across every request to that origin. The homepage asks 127.0.0.1
        // for a document plus a stylesheet, a script, a font and a favicon, and
        // `artisan serve` hands those four back off disk in a millisecond or two. So
        // the set is [1, 2, 2, 2, 3000] and the median is 2ms — Lantern then
        // simulates the *document* at 2ms as well, and a homepage that genuinely
        // took three seconds to answer produces a simulated LCP comfortably under
        // budget. Verified against Lantern's NetworkAnalyzer, not guessed.
        //
        // This audit is immune to that: it reads the observed TTFB off the main
        // document's own network record and never goes near the simulator. It is
        // what actually makes a slow-server breach go red.
        //
        // 600ms is Lighthouse's own failure threshold for this audit, not a budget
        // invented here — worth knowing that Lighthouse asks developers to aim for
        // 100ms. It sits far below the 2.0s LCP budget on purpose: this is a
        // tripwire for a server that has fallen over, not a second performance
        // target competing with the CEO's.
        'server-response-time': ['error', { maxNumericValue: 600, aggregationMethod: 'median' }],

        // Not a CEO budget, so not a failure. They are the two numbers that move
        // first when a page starts getting slow, and a warning in the log is a
        // cheap early signal before LCP actually breaches.
        'total-blocking-time': ['warn', { maxNumericValue: 300, aggregationMethod: 'median' }],
        'first-contentful-paint': ['warn', { maxNumericValue: 1800, aggregationMethod: 'median' }],
      },
    },

    upload: {
      // No LHCI server to run and pay for. The HTML reports are uploaded as a
      // GitHub artifact by the workflow, which is enough to debug a breach.
      target: 'filesystem',
      outputDir: './.lighthouseci',
      reportFilenamePattern: '%%PATHNAME%%-report.%%EXTENSION%%',
    },
  },
};
