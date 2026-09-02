<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Indexable
    |--------------------------------------------------------------------------
    |
    | Whether search engines may index this deployment. **Defaults to false**,
    | and the default is the point: docs/dns.md requires that staging never
    | appears in a search result, and an environment that has to remember to
    | opt *out* eventually forgets. Production opts in explicitly with
    | APP_INDEXABLE=true, which is a line on the cutover checklist.
    |
    | The cost of the two mistakes is not symmetric. A production site briefly
    | carrying noindex loses ranking it does not yet have — the apex is a
    | coming-soon page today. A staging site briefly indexed puts our unfinished
    | work in Google's cache, and removal is a request to a third party rather
    | than a deploy.
    |
    | This is enforced as a header rather than public/robots.txt because
    | robots.txt is a static file served by the web server before PHP is
    | reached, so it cannot vary by environment from inside this repository.
    | X-Robots-Tag is set on every response and cannot be forgotten per-route.
    |
    */

    'indexable' => env('APP_INDEXABLE', false),

    /*
    |--------------------------------------------------------------------------
    | HSTS
    |--------------------------------------------------------------------------
    |
    | Sent only on requests that already arrived over HTTPS — the header is
    | meaningless over plaintext and browsers ignore it there, but sending it
    | locally would mean one visit to http://localhost:8000 pins the whole
    | machine to HTTPS for a year.
    |
    | `include_subdomains` defaults to false on purpose. docs/dns.md: today the
    | apex is served by WordPress.com and a subdomain commitment made from a
    | host we do not control is a year long. Turn it on in our own environment
    | once the apex is ours.
    |
    | There is deliberately no `preload`. Preload is a submission to a list
    | baked into browsers, it commits every subdomain to HTTPS forever, and it
    | is slow and painful to reverse. Revisit a month after cutover.
    |
    */

    'hsts' => [
        'max_age' => (int) env('HSTS_MAX_AGE', 31536000),
        'include_subdomains' => env('HSTS_INCLUDE_SUBDOMAINS', false),
    ],

];
