{{--
    The bare HTML shell every page hangs off. Structure and accessibility only —
    the visual design lives in the Designer's specs (TWO-19, TWO-26) and in the
    components the Frontend Engineer builds. Do not put styling opinions here.
--}}
@php
    $schemeName = $scheme ?? null;
    $lightScheme = in_array($schemeName, ['ledger', 'hallmark', 'taste'], strict: true);
    $bodyScheme = match ($schemeName) {
        'ledger' => 'bg-ledger-paper text-ledger-ink ',
        'taste' => 'bg-taste-paper text-taste-ink ',
        default => '',
    };
    $viteAssets = [
        'resources/css/app.css',
        ...($styles ?? []),
        'resources/js/app.js',
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="{{ $lightScheme ? '' : 'dark ' }}h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $lightScheme ? 'light' : 'dark' }}">
    @if ($robots ?? false)
        <meta name="robots" content="{{ $robots }}">
    @endif
    <title>{{ $title ?? config('app.name') }}</title>
    {{-- Archivo is self-hosted and the headline uses its width axis. Without
         this the hero reflows on first paint and the join button moves. --}}
    <link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>
    @vite($viteAssets)
</head>
<<<<<<< HEAD
<body class="{{ $bodyScheme }}h-full">
=======
<body class="{{ $bodyScheme }}h-full">
>>>>>>> 09e1e72 (Add Taste homepage design lab concept)
    <a href="#main" class="sr-only focus:not-sr-only">Skip to content</a>
    <main id="main">
        {{ $slot }}
    </main>

    @if ($deferLivewire ?? false)
        {{-- The page is complete HTML before Livewire arrives. Starting the runtime
             after window.load keeps its CPU work out of the staged LCP path while
             preserving every calendar and RSVP interaction once loading settles. --}}
        @php
            $livewireTag = \Livewire\Mechanisms\FrontendAssets\FrontendAssets::js([]);
            preg_match('/\bsrc="([^"]+)"/', $livewireTag, $livewireSource);
        @endphp
        @livewireScriptConfig
        <script>
            window.addEventListener('load', () => {
                const livewire = document.createElement('script');
                livewire.src = @js(html_entity_decode($livewireSource[1] ?? ''));
                livewire.onload = () => Livewire.start();
                document.head.appendChild(livewire);
            }, { once: true });
        </script>
    @endif
</body>
</html>
