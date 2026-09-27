{{--
    Shared-link tags for the join funnel (TOG-5624).

    Home, join and events live on shared links, so each carries its own
    canonical URL plus OG and Twitter Card tags. Every URL here arrives as an
    absolute URL built with `route()`/`url()` from APP_URL — never a hardcoded
    hostname (see tests/Unit/NoHardcodedHostnamesTest.php).

    There is deliberately no `og:image`: the repo ships no share artwork and a
    favicon is not a valid OG image, so pages declare a `summary` card rather
    than pointing at an image that does not exist.
--}}
<link rel="canonical" href="{{ $canonical }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:title" content="{{ $shareTitle }}">
@if ($shareDescription)
<meta property="og:description" content="{{ $shareDescription }}">
@endif
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $shareTitle }}">
@if ($shareDescription)
<meta name="twitter:description" content="{{ $shareDescription }}">
@endif
