<?php

namespace App\Providers\Filament;

use App\Http\Middleware\RecordMemberDataAccess;
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
            ->brandName('TWO Moderation')
            ->colors([
                // --color-brand from resources/css/two.css. Filament wants a
                // shade map, not a CSS variable, so the crimson is restated
                // here; two.css stays the source of truth for the public site.
                'primary' => Color::hex('#c80154'),
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
            ])
            ->authMiddleware([
                Authenticate::class,
                RecordMemberDataAccess::class,
            ]);
    }
}
