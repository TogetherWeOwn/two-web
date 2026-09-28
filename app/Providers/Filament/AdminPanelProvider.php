<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AddContentSecurityPolicy;
use App\Http\Middleware\RecordMemberDataAccess;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The moderator panel.
 *
 * Two decisions here are load-bearing and easy to undo by accident:
 *
 * There is no `->login()`. The site's only identity is Discord OAuth
 * (routes/web.php `login`), so a guest who hits /admin is redirected into that
 * flow like everyone else. A signed-in member who is not a moderator never sees
 * a login page: Filament's Authenticate middleware asks User::canAccessPanel()
 * and aborts 403 — the behaviour TOG-54 requires (a 403, not a login loop).
 *
 * RecordMemberDataAccess is the CISO's condition on this panel (TOG-355): reads
 * of member data through any panel screen are recorded before the response is
 * served, with subjects collected from Eloquent hydration so a new resource
 * cannot forget to declare itself. Removing it from this stack removes the
 * evidence that makes the narrow-first moderator role safe.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Dropping only the <link rel=preload> left the Inter Variable @font-face
            // (and its 68KB woff2) still discovered via theme.css and fetched anyway —
            // font-display:swap doesn't block first paint, but Lighthouse's LCP audit
            // still counts the later swap repaint as the final candidate, so the
            // request stayed on the critical path regardless of preload. This panel is
            // internal moderator tooling, not the branded public site (two.css owns
            // that), so it renders in the system font stack instead: zero font
            // requests, no swap repaint, nothing left to compete with theme.css and
            // livewire.js for the budgets job's throttled bandwidth. See TOG-3332.
            ->font('ui-sans-serif, system-ui, -apple-system, sans-serif', provider: LocalFontProvider::class)
            ->brandName('TWO Moderation')
            ->colors([
                // --color-brand from resources/css/two.css. Filament wants a
                // shade map, not a CSS variable, so the crimson is restated
                // here; two.css stays the source of truth for the public site.
                //
                // Shade 600 is overridden because Filament generates the ramp by
                // holding chroma and hue and walking lightness, and the 600 it
                // derives — oklch(0.5978 …), #cf4a6c — carries white text at
                // 4.33:1. That is the background of every primary action button in
                // dark mode, which this panel forces, so "New featured content"
                // failed WCAG 2.2 AA 1.4.3 and ci/a11y.mjs failed the build. The
                // brand crimson itself is fine (5.85:1); only this derived shade
                // is not, so the fix darkens the one shade rather than changing
                // the brand.
                //
                // 0.575 measured in Chrome at 4.78:1, not calculated: an oklch
                // string has to be rasterised to sRGB before a contrast ratio
                // means anything, and reading Chrome's computed value as if it
                // were RGB gives a confidently wrong number. The threshold sits at
                // 0.585 (4.60:1); this is two steps darker for margin.
                //
                // `+` and not a spread. The shade map is keyed by integers
                // (50…950), and `[...$map]` renumbers integer keys from zero — it
                // silently turns the ramp into 0…10, Filament finds no shade it
                // recognises, and every panel page 500s. The union operator keeps
                // the left operand's keys, so the 600 here wins and the rest of
                // the generated ramp is untouched.
                'primary' => [600 => 'oklch(0.575 0.169136 8.359)'] + Color::hex('#c80154'),
            ])
            // Forced dark: the design system (two.css) is dark-only, and a
            // light admin next to a dark site is exactly the "different
            // product" feel TOG-54 rules out.
            ->darkMode(isForced: true)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                // Same CSP as the public `web` stack (TOG-6770), which this
                // panel does not use — without it /admin pages ship no policy.
                AddContentSecurityPolicy::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RecordMemberDataAccess::class,
            ]);
    }
}
