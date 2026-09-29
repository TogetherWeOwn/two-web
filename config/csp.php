<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CSP report-only mode (TOG-8403)
    |--------------------------------------------------------------------------
    |
    | When false (the default), AddContentSecurityPolicy emits the enforcing
    | `Content-Security-Policy` header exactly as before. When true, it emits
    | `Content-Security-Policy-Report-Only` instead — same policy, plus
    | `report-uri /csp-reports` — so violations are logged without blocking.
    | Flip this on to tune the policy against real traffic, then flip it back
    | to enforce. Boolean, read from env so no deploy is needed to toggle it.
    */

    'report_only' => (bool) env('CSP_REPORT_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | CSP report sampling (TOG-8403)
    |--------------------------------------------------------------------------
    |
    | Fraction of valid violation reports (0.0–1.0) written to the log.
    | POST /csp-reports is an unauthenticated sink, so this is the flood
    | control alongside the body-size cap in CspReportController. 1.0 logs
    | everything; lower it if report volume ever outweighs the signal.
    */

    'report_sample_rate' => (float) env('CSP_REPORT_SAMPLE_RATE', 1.0),

];
