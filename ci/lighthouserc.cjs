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

const { urls } = require('./pages.cjs');

module.exports = {
  ci: {
    collect: {
      url: urls,

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
