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
            // Owner 2026-11-17: DANA logo left of the brand text in the
            // sidebar header (RISE-style brand row). brandLogo replaces the
            // brandName markup, so the text is carried inside the HTML; the
            // .fi-logo rule in app.css centers the pair. NOTE: a plain string
            // is treated as an image PATH (dropped into src="") — it must be
            // an Htmlable or the markup renders as a broken-image alt text.
            ->brandLogo(new \Illuminate\Support\HtmlString('<img src="'.asset('images/favicon.png').'?v=3" alt="DANA logo" class="h-8 w-8"><span>TechRisk Portal</span>'))
            ->brandLogoHeight('2rem')
            // Owner 2026-11-17: the browser tab showed the default icon —
            // public/favicon.ico was an empty file. DANA logo, square-cropped
            // from the source artwork (public/images/favicon.png); the .ico
            // covers the browser's default /favicon.ico request (Safari).
            // ?v=3: the same URL served the dark-fringed rebuild — bust it.
            ->favicon(asset('images/favicon.png').'?v=3')
            ->sidebarWidth('18rem')
            // 4.5rem (Filament default) clips the rail icons: nav px-6 +
            // item px-2 + a 1.5rem icon needs 5.5rem to render full-size.
            ->collapsedSidebarWidth('5.5rem')
            ->sidebarCollapsibleOnDesktop()
            // Owner 2026-11-18: the collapsed rail's expand control is the
            // DANA logo itself — the brandLogo wrapper is CSS-promoted into
            // the collapsed header (app.css); the pin lives only on the
            // temporary expand state (SIDEBAR_NAV_START hook below).
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
            )
            // Owner 2026-11-18: when the sidebar is collapsed the DANA logo
            // lives in the RAIL header (app.css promotes the brandLogo
            // wrapper there) — the topbar shows the brand copy TEXT ONLY,
            // no second logo beside the rail's.
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                // x-cloak hides the copy until Alpine registers the sidebar
                // store (Filament's JS) — no flash on expanded desktop loads.
                fn (): string => '<a href="'.filament()->getHomeUrl().'" class="fi-topbar-brand" x-cloak x-show="! $store.sidebar.isOpen">'
                    .'<span>TechRisk Portal</span>'
                    .'</a>',
            )
            // Owner 2026-11-17 (DANA HR reference): one control button, three
            // functions — collapse (chevron, when open), temporary expand
            // (hover over the collapsed rail, script below), pinned (this
            // button: visible only in the temporary state, click = permanent
            // expand). Server-rendered inside the nav so Livewire morphs keep
            // it; CSS fixes it over the header's right edge.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => '<button type="button" class="fi-tr-pin-btn" title="Pin sidebar" aria-label="Pin sidebar">'
                    .'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6">'
                    .'<path stroke-linecap="round" stroke-linejoin="round" d="M12 17v5M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1z"/>'
                    .'</svg></button>',
            )
            // The hover behaviour: entering the collapsed rail temporarily
            // opens it (overlay, CSS in app.css), leaving closes it again.
            // Delegated on document (mouseover/out bubble; mouseenter doesn't)
            // so Livewire morphs of the aside can't drop the listeners.
            // $store.sidebar.isOpen is Alpine-persisted, so the pinned/collapsed
            // choice survives reloads. Desktop only — mobile keeps the
            // off-canvas hamburger.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn (): string => <<<'HTML'
                    <script>
                        // State + helpers live on var-globals: in-app
                        // navigations re-execute this script, and the re-run
                        // must share the SAME bindings the registered
                        // listeners close over (let/const would collide across
                        // script evals; var redeclares cleanly).
                        var trOverSidebar = false
                        var trTempOpen = false

                        var trSidebarEl = () => document.querySelector('.fi-sidebar')

                        var trSetPinned = (pinned) => localStorage.setItem('tr-sidebar-pinned', pinned ? '1' : '0')

                        var trEnterSidebar = () => {
                            if (! window.matchMedia('(min-width: 1024px)').matches) return
                            const sidebar = window.Alpine?.store?.('sidebar')
                            if (! sidebar || sidebar.isOpen) return
                            trTempOpen = true
                            trSetPinned(false)
                            trSidebarEl()?.classList.add('tr-sidebar-temp-open')
                            sidebar.open()
                        }

                        var trLeaveSidebar = () => {
                            if (! trTempOpen) return
                            trTempOpen = false
                            trSidebarEl()?.classList.remove('tr-sidebar-temp-open')
                            window.Alpine?.store?.('sidebar')?.close()
                        }

                        if (! window.__trSidebarBehavior) {
                            window.__trSidebarBehavior = true

                            // Delegated on document (mouseover/out bubble;
                            // mouseenter doesn't) so a swapped aside element
                            // can't drop the listeners.
                            document.addEventListener('mouseover', (event) => {
                                if (trOverSidebar || ! event.target.closest?.('.fi-sidebar')) return
                                trOverSidebar = true
                                trEnterSidebar()
                            })

                            document.addEventListener('mouseout', (event) => {
                                if (! trOverSidebar) return
                                if (event.relatedTarget?.closest?.('.fi-sidebar')) return
                                trOverSidebar = false
                                trLeaveSidebar()
                            })

                            document.addEventListener('click', (event) => {
                                if (event.target.closest?.('.fi-tr-pin-btn')) {
                                    trTempOpen = false
                                    trSidebarEl()?.classList.remove('tr-sidebar-temp-open')
                                    trSetPinned(true)
                                    return
                                }
                                // Filament's header chevrons (collapse, or keyboard
                                // expand): record the resulting mode so a reload
                                // restores the user's choice.
                                if (event.target.closest?.('.fi-sidebar-header .fi-icon-btn')) {
                                    trSetPinned(!! window.Alpine?.store?.('sidebar')?.isOpen)
                                }
                            })
                        }

                        // Re-sync on every load/navigation: a page load while
                        // hovering (or right after clicking a nav item from the
                        // temporary state) persists isOpen=true — strip that
                        // residue unless the sidebar was actually PINNED. When
                        // the cursor rests inside the sidebar, the temporary
                        // state simply continues. On the first load Filament's
                        // bundle is deferred, so the strip waits for
                        // DOMContentLoaded; on in-app navigations the script
                        // re-runs with Alpine already live.
                        var trStripResidue = () => {
                            if (! window.matchMedia('(min-width: 1024px)').matches) return
                            const sidebar = window.Alpine?.store?.('sidebar')
                            if (! sidebar) return
                            trOverSidebar = !! trSidebarEl()?.matches(':hover')
                            const pinned = localStorage.getItem('tr-sidebar-pinned') === '1'

                            if (trOverSidebar) {
                                // The cursor rests inside the sidebar: a
                                // non-pinned open continues as a temporary
                                // expand (leaving still collapses it).
                                trTempOpen = ! pinned
                                if (! pinned) {
                                    trSetPinned(false)
                                    trSidebarEl()?.classList.add('tr-sidebar-temp-open')
                                }
                                if (! sidebar.isOpen) sidebar.open()
                                return
                            }

                            if (sidebar.isOpen && ! pinned) {
                                trTempOpen = false
                                sidebar.close()
                            }
                        }

                        // The sidebar store registers with Filament's bundle,
                        // which lands AFTER DOMContentLoaded on the first load
                        // and is re-initialised after each in-app navigation —
                        // wait for it (50ms steps, 5s cap) instead of guessing
                        // a moment it exists.
                        var trWaitForSidebar = (attempt) => {
                            const sidebar = window.Alpine?.store?.('sidebar')
                            if (sidebar) {
                                trStripResidue()
                                return
                            }
                            if (attempt > 100) return
                            setTimeout(() => trWaitForSidebar(attempt + 1), 50)
                        }
                        trWaitForSidebar(0)
                    </script>
                HTML,
            );
    }
}
