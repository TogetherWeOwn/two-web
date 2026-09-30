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
    <meta name="theme-color" content="#0b0714">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="icon" href="/icons/icon-192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png" sizes="180x180">
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
    {{-- Feed autodiscovery (TOG-7939). Every page advertises the events feed so
         readers find it without a pasted URL; the feed itself carries the
         matching atom:link rel="self". Unconditional: the URL is stable and
         public, and a conditional risks pages that silently opt out. --}}
    <link rel="alternate" type="application/rss+xml" title="{{ config('app.name') }} Events" href="{{ route('events.rss') }}">
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
    {{-- TOG-6932: the old `sr-only focus:not-sr-only` revealed the link as bare
         text on the page ground — a 1.4:1 smear on ledger paper. The pill the
         revealed state needs (opaque fill, padding, focus outline) lives in
         resources/css/app.css as `a[href='#main']:focus-visible`, so the class
         list here stays structural: hidden until focused, then handed to CSS. --}}
    <a href="#main" class="sr-only focus:not-sr-only">Skip to content</a>
    {{-- `tabindex="-1"`: the skip-link target must take programmatic focus in
         Chrome/Safari, where a plain anchor jump scrolls but leaves focus on
         `body` — a keyboard user who skips then tabs starts over at the top.
         `-1` keeps it out of the tab order while making it a focus target;
         the :focus-visible ring still marks it when the skip link lands. --}}
    <main id="main" tabindex="-1">
        {{ $slot }}
    </main>

    @auth
        {{-- Cross-tab sign-out (TOG-8136). Logout destroys the session
             server-side but a second tab keeps rendering @auth controls —
             RSVP buttons, attendee names, the profile — until its next load,
             and the next click there 302s with no explanation. This script
             closes that gap two ways:
               1. `storage` event: the tab that submits the logout form writes
                  `two-auth = signed-out` first, so a side-by-side tab reloads
                  at once instead of waiting to be looked at.
               2. visibility/focus/pageshow re-check: the tab asks
                  `GET auth.status`, and a `false` reloads it into the guest
                  render — the same page a manual refresh already shows on a
                  dead session, so no new bounce is invented here. This path
                  also covers session expiry (SESSION_LIFETIME), which has no
                  logout form to broadcast from.
             Guests get none of this (zero bytes, no probe): a signed-out page
             is already the correct render. A failed probe — offline, 429, 500 —
             leaves the page exactly as it is; only a positive `false` reloads,
             so this can never log a member out. Inline rather than a Vite
             entry: it must run on every authenticated page, and the global
             bundle is pinned import-free (AssetCompressionTest). --}}
        <script data-testid="auth-tab-sync">
            (() => {
                // Livewire morphs can re-insert this block; run once per page.
                if (window.__twoAuthTabSync) {
                    return;
                }
                window.__twoAuthTabSync = true;

                const STORAGE_KEY = 'two-auth';
                const STATUS_URL = @js(route('auth.status'));

                try {
                    window.localStorage.setItem(STORAGE_KEY, 'signed-in');
                } catch (e) {
                    // Private mode or disabled storage: the server re-check
                    // below still works, the instant path just stays quiet.
                }

                let checking = false;

                async function recheck() {
                    if (checking || document.visibilityState === 'hidden') {
                        return;
                    }
                    checking = true;
                    try {
                        const res = await fetch(STATUS_URL, {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                        });
                        if (res.ok) {
                            const body = await res.json();
                            if (body && body.authenticated === false) {
                                window.location.reload();
                            }
                        }
                    } catch (e) {
                        // Offline or failing: leave the page as-is. The next
                        // visibility change tries again.
                    } finally {
                        checking = false;
                    }
                }

                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') {
                        recheck();
                    }
                });
                window.addEventListener('focus', recheck);
                window.addEventListener('pageshow', (event) => {
                    if (event.persisted) {
                        recheck();
                    }
                });
                window.addEventListener('storage', (event) => {
                    if (event.key === STORAGE_KEY && event.newValue === 'signed-out') {
                        window.location.reload();
                    }
                });

                // The logout form lives in the member profile (and any future
                // twin): delegation survives Livewire morphs, and the write is
                // synchronous so it lands before the POST navigates away.
                document.addEventListener('submit', (event) => {
                    const form = event.target;
                    if (form instanceof HTMLFormElement && form.action.endsWith('/logout')) {
                        try {
                            window.localStorage.setItem(STORAGE_KEY, 'signed-out');
                        } catch (e) {
                            // Same degraded path as above: the re-check covers it.
                        }
                    }
                });
            })();
        </script>
    @endauth

    @if ($deferLivewire ?? false)
        {{-- TOG-7927: the pre-boot guard. Until the deferred runtime below boots,
             `wire:click` handlers are unbound — a rendered "I'm in" button takes
             the tap and does nothing, with no announcement. Buttons are disabled
             so the tap is impossible (a screen reader hears "dimmed", not
             silence); links, which have no `disabled`, are stopped at capture
             and answered politely instead. `livewire:initialized` lifts both.
             The ready announcement fires only when a tap was actually blocked
             earlier — announcing on every load would interrupt a member who
             never touched anything. --}}
        <p class="sr-only" role="status" data-testid="livewire-boot-status">Loading interactive controls…</p>
        <script>
            (function () {
                let ready = false;
                let blocked = false;
                const booted = () =>
                    ready || (window.Livewire && window.Livewire.initialRenderIsFinished === true);

                const disablePrebootControls = () => {
                    document.querySelectorAll('button[wire\\:click]').forEach((el) => {
                        if (!el.hasAttribute('disabled')) {
                            el.setAttribute('disabled', '');
                            el.setAttribute('data-preboot-disabled', 'true');
                        }
                    });
                    document.querySelectorAll('a[wire\\:click]').forEach((el) => {
                        el.setAttribute('aria-disabled', 'true');
                        el.setAttribute('data-preboot-disabled', 'true');
                    });
                };

                const announceBlocked = () => {
                    blocked = true;
                    const status = document.querySelector('[data-testid="livewire-boot-status"]');
                    if (status) {
                        status.textContent = 'Still loading — try again in a moment. Nothing changed.';
                    }
                };

                // `aria-disabled` does not block activation, so blocked link taps
                // are answered here, at capture, before they can no-op. Disabled
                // buttons never reach this handler — that is the point. A link
                // with a real destination (the calendar day-links jump to
                // `#event-…`) keeps its native navigation — stopping that would
                // trade one silence for a second — and the announcement still
                // names the loading state beside it.
                document.addEventListener('click', (event) => {
                    if (booted()) return;
                    const target = event.target && typeof event.target.closest === 'function'
                        ? event.target.closest('a[wire\\:click]')
                        : null;
                    if (target) {
                        const href = target.getAttribute('href') || '';
                        if (href === '' || href.charAt(0) !== '#') {
                            event.preventDefault();
                        }
                        event.stopPropagation();
                        announceBlocked();
                    }
                }, true);

                document.addEventListener('livewire:initialized', () => {
                    ready = true;
                    document.querySelectorAll('[data-preboot-disabled]').forEach((el) => {
                        el.removeAttribute('data-preboot-disabled');
                        if (el.tagName === 'A') {
                            el.removeAttribute('aria-disabled');
                        } else {
                            el.removeAttribute('disabled');
                        }
                    });
                    // Only speak when somebody was actually stopped earlier: a
                    // member who never touched a control hears nothing, not a
                    // load announcement. When spoken, the text is cleared either
                    // way so the stale "still loading" line never lingers.
                    const status = document.querySelector('[data-testid="livewire-boot-status"]');
                    if (status) {
                        status.textContent = blocked ? 'Interactive controls ready.' : '';
                    }
                });

                disablePrebootControls();
            })();
        </script>
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
