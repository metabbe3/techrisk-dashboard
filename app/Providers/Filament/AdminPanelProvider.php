<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\CustomProfilePage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\WeeklyReport;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
        // Owner 2026-11-17: the sidebar follows the RISE portal reference and
        // the panel switches to DANA blue. 'indigo' is overridden with the same
        // palette because the primary WAS indigo — every existing 'indigo'
        // badge/accent keeps its exact relationship to primary. 'info' moves
        // Sky -> Cyan: Sky (#0ea5e9) is near-identical to the new primary.
        $dana = [
            50 => '238, 247, 254',
            100 => '216, 236, 253',
            200 => '182, 222, 251',
            300 => '127, 203, 247',
            400 => '56, 175, 241',
            500 => '16, 142, 233',
            600 => '6, 115, 201',
            700 => '8, 92, 163',
            800 => '12, 78, 134',
            900 => '15, 66, 111',
            950 => '9, 43, 73',
        ];

        return $panel
            ->default()
            ->id('admin')
            ->brandName('TechRisk Portal')
            // Owner 2026-11-17: the browser tab showed the default icon —
            // public/favicon.ico was an empty file. DANA logo, square-cropped
            // from the source artwork (public/images/favicon.png); the .ico
            // covers the browser's default /favicon.ico request (Safari).
            ->favicon(asset('images/favicon.png'))
            ->sidebarWidth('17rem')
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->globalSearch(true)
            ->path('admin')
            ->login(Login::class)
            ->passwordReset()
            ->profile(CustomProfilePage::class)
            ->colors([
                'primary' => $dana,
                'indigo' => $dana,
                'danger' => Color::Rose,
                'warning' => Color::Amber,
                'success' => Color::Emerald,
                'info' => Color::Cyan,
                'gray' => Color::Slate,
                'violet' => Color::Violet,
                'teal' => Color::Teal,
                'orange' => Color::Orange,
                'cyan' => Color::Cyan,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->pages([
                Dashboard::class,
                WeeklyReport::class,
                \App\Filament\Pages\AnalyticsPage::class,
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
            ])
            ->renderHook(
                'panels::head.end',
                fn () => '<link rel="stylesheet" href="'.Vite::asset('resources/css/app.css').'">'
                    .'<script type="module" src="'.Vite::asset('resources/js/app.js').'"></script>'
            )
            // "Sign in with Microsoft" button + SSO flash message, below the login form
            // (the hook renders after the closing </form>, so the anchor can't submit it).
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): string => view('auth.microsoft-sso')->render(),
            );
    }
}
