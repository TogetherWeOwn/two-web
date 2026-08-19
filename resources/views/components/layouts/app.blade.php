{{--
    The bare HTML shell every page hangs off. Structure and accessibility only —
    the visual design lives in the Designer's specs (TWO-19, TWO-26) and in the
    components the Frontend Engineer builds. Do not put styling opinions here.
--}}
<!DOCTYPE html>
<html lang="en" class="dark h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Dark only, by design. The meta tag lands before the stylesheet parses,
         so scrollbars and form controls never flash light on first paint. --}}
    <meta name="color-scheme" content="dark">
    <title>{{ $title ?? config('app.name') }}</title>
    {{-- Archivo is self-hosted and the headline uses its width axis. Without
         this the hero reflows on first paint and the join button moves. --}}
    <link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
    <a href="#main" class="sr-only focus:not-sr-only">Skip to content</a>
    <main id="main">
        {{ $slot }}
    </main>
</body>
</html>
