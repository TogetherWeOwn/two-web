{{--
    The bare HTML shell every page hangs off. Structure and accessibility only —
    the visual design lives in the Designer's specs (TWO-19, TWO-26) and in the
    components the Frontend Engineer builds. Do not put styling opinions here.
--}}
@php
    $ledgerScheme = ($scheme ?? null) === 'ledger';
@endphp
<!DOCTYPE html>
<html lang="en" class="{{ $ledgerScheme ? '' : 'dark ' }}h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="{{ $ledgerScheme ? 'light' : 'dark' }}">
    <title>{{ $title ?? config('app.name') }}</title>
    {{-- Archivo is self-hosted and the headline uses its width axis. Without
         this the hero reflows on first paint and the join button moves. --}}
    <link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="{{ $ledgerScheme ? 'bg-ledger-paper text-ledger-ink ' : '' }}h-full">
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
