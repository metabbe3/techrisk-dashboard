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
use pxlrbt\FilamentSpotlight\SpotlightPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(\Filament\Panel $panel): \Filament\Panel
    {
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
            // Sidebar geometry (open 15rem / rail 4.5rem) is owned by the
            // app.css redesign block (main a0c3e30) — no provider width
            // overrides, or the two fight at different layers.
            // Icon rail on desktop (was fully-collapsible): with the top bar
            // hidden on desktop, a fully-hidden sidebar would have no button
            // left to reopen it — the rail keeps logo + nav icons reachable.
            // (Both chains converged here; ours also carries the hover
            // temp-expand + pin on the rail.)
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
            ->plugins([
                SpotlightPlugin::make(),
            ])
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
            // Owner 2026-11-18: fixed group order — Dashboard (ungrouped
            // home) sits above all groups, then these. NavigationManager
            // sorts groups by their index HERE (array_search), so the
            // registration order IS the sidebar order; a group left out
            // would render after every listed one. Every group starts
            // COLLAPSED except Operations (PROJ-025) — Filament seeds
            // localStorage 'collapsedGroups' on first visit from
            // NavigationGroup::isCollapsed().
            ->navigationGroups([
                NavigationGroup::make()->label('Operations'),
                NavigationGroup::make()->label('Reports')->collapsed(),
                NavigationGroup::make()->label('AI')->collapsed(),
                NavigationGroup::make()->label('Notifications')->collapsed(),
                NavigationGroup::make()->label('User Management')->collapsed(),
                NavigationGroup::make()->label('Settings')->collapsed(),
                NavigationGroup::make()->label('System')->collapsed(),
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
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): \Illuminate\Contracts\View\View => view('filament.sidebar.search-trigger'),
            )
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
            ->renderHook(
                'panels::head.end',
                fn () => '<link rel="stylesheet" href="'.Vite::asset('resources/css/app.css').'">'
                    .'<script type="module" src="'.Vite::asset('resources/js/app.js').'"></script>'
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
            // Owner 2026-11-18 (RISE reference): today's date opens a MONTH
            // calendar — weeks start MONDAY. Sits in the topbar's right
            // cluster before the bell (GLOBAL_SEARCH_AFTER is the hook
            // between search and the bell/avatar block). Pure display — no
            // date-picking action. Alpine logic inlines below the markup;
            // the topbar markup is outside every Livewire root, so the
            // one-time registration survives morphs.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): string => '<div class="fi-tr-date" x-data="trCalendar()" @click.outside="open = false">'
                    .'<button type="button" class="fi-tr-date-btn" @click="toggle()" aria-label="Open calendar">'
                    .'<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>'
                    .'<span>'.now()->format('M j, Y').'</span>'
                    .'</button>'
                    .'<div class="fi-tr-cal" x-cloak x-show="open" x-transition.opacity.duration.150ms>'
                    .'<div class="fi-tr-cal-head">'
                    .'<button type="button" @click="shift(-1)" aria-label="Previous month">&lsaquo;</button>'
                    .'<strong x-text="label()"></strong>'
                    .'<button type="button" @click="shift(1)" aria-label="Next month">&rsaquo;</button>'
                    .'</div>'
                    .'<div class="fi-tr-cal-weekdays"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span class="weekend">Sat</span><span class="weekend">Sun</span></div>'
                    .'<div class="fi-tr-cal-days">'
                    .'<template x-for="cell in cells()" :key="cell.key">'
                    .'<span :class="{ \'dim\': cell.dim, \'today\': cell.today, \'weekend\': cell.weekend }" x-text="cell.day"></span>'
                    .'</template>'
                    .'</div>'
                    .'</div>'
                    .'</div>'
                    .'<script>'
                    .'if (! window.trCalendar) { window.trCalendar = function () { return {'
                    .' open: false, cursor: null, today: new Date(),'
                    .' toggle() { this.open = ! this.open; if (this.open && ! this.cursor) this.cursor = new Date() },'
                    .' label() { return this.cursor.toLocaleString("en-US", { month: "long", year: "numeric" }) },'
                    .' shift(n) { this.cursor = new Date(this.cursor.getFullYear(), this.cursor.getMonth() + n, 1) },'
                    .' cells() { const first = new Date(this.cursor.getFullYear(), this.cursor.getMonth(), 1);'
                    .'  const offset = (first.getDay() + 6) % 7;' // Monday = column 0
                    .'  const start = new Date(first); start.setDate(first.getDate() - offset);'
                    .'  const out = [];'
                    .'  for (let i = 0; i < 42; i++) { const d = new Date(start); d.setDate(start.getDate() + i);'
                    .'   out.push({ key: i, day: d.getDate(), dim: d.getMonth() !== this.cursor.getMonth(), today: d.toDateString() === this.today.toDateString(), weekend: d.getDay() === 0 || d.getDay() === 6 }) }'
                    .'  return out },'
                    .'} } }'
                    .'</script>',
            )
            // Owner 2026-11-18 (RISE reference): the account control shows
            // the user's NAME beside the avatar — owner 2026-11-19: the name
            // sits to the RIGHT of the avatar (USER_MENU_AFTER). Clicking the
            // name opens the same menu (it clicks the dropdown's trigger
            // button, the dropdown wrapper's PREVIOUS sibling). Hidden on
            // <768px: the mobile topbar is cramped.
            ->renderHook(
                PanelsRenderHook::USER_MENU_AFTER,
                fn (): string => auth()->check()
                    ? '<span class="fi-tr-user-name" x-data x-on:click="$el.previousElementSibling?.querySelector(\'button\')?.click()" title="'.e(filament()->getUserName(auth()->user())).'">'
                        .e(filament()->getUserName(auth()->user()))
                        .'</span>'
                    : '',
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
        // "Sign in with Microsoft" button + SSO flash message moved into
        // App\Filament\Pages\Auth\Login (split-screen page view) — main
        // 60f4587; the old AUTH_LOGIN_FORM_AFTER hook here is gone so the
        // button renders once.
    }
}
