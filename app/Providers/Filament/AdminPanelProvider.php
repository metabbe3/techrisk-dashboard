<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\CustomProfilePage;
use App\Filament\Pages\Dashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(\Filament\Panel $panel): \Filament\Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->brandName('TechRisk Portal')
            // Icon rail on desktop (was fully-collapsible): with the top bar
            // hidden on desktop, a fully-hidden sidebar would have no button
            // left to reopen it — the rail keeps logo + nav icons reachable.
            ->sidebarCollapsibleOnDesktop()
            // Topbar bell is OFF — the sidebar footer shows the bell row
            // (App\Livewire\SidebarNotificationBell) and the slide-over modal
            // is mounted at panels::body.end instead
            // (App\Livewire\SidebarDatabaseNotifications, trigger-free).
            ->databaseNotifications(false)
            ->globalSearch(true)
            // Inter Variable is self-hosted through the app's Vite build
            // (@fontsource-variable/inter imported in resources/css/app.css) —
            // no font CDN, which matters on the VPN-only prod domain.
            // ->font() sets the panel's --font-family CSS var.
            ->font('Inter Variable', provider: \Filament\FontProviders\LocalFontProvider::class)
            // Command palette (pxlrbt/filament-spotlight) deleted 2026-11-20 —
            // owner: it let restricted roles reach page commands past their
            // nav gates. The composer package stays until the parallel
            // session's composer churn settles.
            ->path('admin')
            ->login(Login::class)
            ->passwordReset()
            ->profile(CustomProfilePage::class)
            ->colors([
                // DANA brand blue (owner 2026-11-19: no purple anywhere).
                // 600 = #108EE9 is the brand (Filament buttons use 600).
                // Mirrors the --color-dana-* @theme tokens in app.css — keep in sync.
                'primary' => [
                    50 => '#f0f9fe', 100 => '#dcf0fc', 200 => '#b8e1fa', 300 => '#7fc8f4',
                    400 => '#42adef', 500 => '#0f7bd1', 600 => '#108ee9', 700 => '#0c67ad',
                    800 => '#0a5589', 900 => '#083e66',
                ],
                'dana' => [
                    50 => '#f0f9fe', 100 => '#dcf0fc', 200 => '#b8e1fa', 300 => '#7fc8f4',
                    400 => '#42adef', 500 => '#0f7bd1', 600 => '#108ee9', 700 => '#0c67ad',
                    800 => '#0a5589', 900 => '#083e66',
                ],
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
                'info' => Color::Sky,
                'gray' => Color::Slate,
                'violet' => Color::Violet,
                'teal' => Color::Teal,
                'orange' => Color::Orange,
                'cyan' => Color::Cyan,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // Same-meaning pages/resources share one sidebar item via clusters
            // (AI / Reports / Notifications / Settings / User Management / Logs);
            // each member renders as a top tab. Operations stays a flat group.
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            // WeeklyReport + AnalyticsPage are NOT listed here: they are
            // ReportsCluster members, and a second registration here renders
            // their cluster tabs twice.
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()->label('Operations'),
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
                \App\Http\Middleware\RedirectToPanelHome::class,
            ])
            ->renderHook(
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn (): \Illuminate\Contracts\View\View => view('filament.sidebar.footer'),
            )
            // The database-notifications slide-over modal. Body level (NOT the
            // sidebar aside): the aside's transform classes re-anchor
            // position:fixed descendants — see
            // App\Livewire\SidebarDatabaseNotifications for the full story.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): \Illuminate\Contracts\View\View => view('filament.sidebar.notifications-modal'),
            )
            // Cluster tab strips wrap instead of scrolling when the window is
            // narrow (owner: 9 AI tabs "overload" on overflow). Higher
            // specificity beats Filament's own .fi-tabs rule at any order.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
                    '<style>.fi-page-sub-navigation-tabs.fi-tabs{flex-wrap:wrap}</style>'
                ),
            )
            ->renderHook(
                'panels::head.end',
                fn () => '<link rel="stylesheet" href="'.Vite::asset('resources/css/app.css').'">'
                    .'<script type="module" src="'.Vite::asset('resources/js/app.js').'"></script>'
            );
        // "Sign in with Microsoft" button + SSO flash message moved into
        // App\Filament\Pages\Auth\Login (split-screen page view).
    }
}
