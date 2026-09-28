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

    // hallmark.css is the Hallmark concept's entire stylesheet and every rule in
    // it is nested under `.hallmark-page`, which exists on exactly one route.
    // Listing it unconditionally above put a second render-blocking stylesheet —
    // 10.9KB raw, 2.7KB gzipped, plus its own request — in front of the paint on
    // every public page, to style an element those pages do not have. On /events
    // that is ~20% of the CSS the browser downloads and parses before it can
    // render anything, spent on a `noindex` design lab (TOG-3233).
    //
    // Keyed off the scheme rather than a hand-passed list so a page cannot ask
    // for the hallmark look and silently not get its stylesheet.
    $styleBundles = array_merge(
        ['resources/css/app.css'],
        $schemeName === 'hallmark' ? ['resources/css/hallmark.css'] : [],
    );
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
    {{-- Share tags (TOG-5624). A page opts in by passing `canonical`; the OG /
         Twitter title falls back to the page <title> so the two cannot drift
         apart. Pages without a canonical get no share tags. --}}
    @if (isset($canonical))
        @include('partials.share-meta', [
            'canonical' => $canonical,
            'shareTitle' => $shareTitle ?? $title ?? config('app.name'),
            'shareDescription' => $shareDescription ?? null,
        ])
    @endif
    {{-- Archivo is self-hosted and the headline uses its width axis. Without
         this the hero reflows on first paint and the join button moves. --}}
    <link rel="preload" href="/fonts/archivo-latin.woff2" as="font" type="font/woff2" crossorigin>
    @if ($deferLivewire ?? false)
        {{-- Livewire's loading/offline hiding rules, inline and immediate. The
             deferred loader below emits @livewireScriptConfig, which flips
             FrontendAssets::hasRenderedScripts and makes auto-injection skip
             the styles too — so without this the page ships zero <style> tags
             and every wire:loading spinner renders visibly until the runtime
             boots on window.load (TOG-7335). CSS parses now; only the JS waits. --}}
        @livewireStyles
    @endif
    @vite([...$styleBundles, 'resources/js/app.js'])
</head>
<body class="{{ $bodyScheme }}h-full">
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
