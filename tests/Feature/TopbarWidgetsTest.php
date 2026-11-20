<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Owner 2026-11-18 (RISE topbar reference): the topbar's right cluster
 * carries today's date — clicking it opens a MONTH calendar whose weeks
 * start MONDAY — and the account control shows the user's NAME beside the
 * avatar circle, not the bare initials button.
 */
class TopbarWidgetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_topbar_shows_todays_date_with_a_monday_first_calendar(): void
    {
        $today = Carbon::now()->format('M j, Y');

        $response = $this->actingAs($this->adminUser())
            ->get('/admin');
        $response->assertSuccessful()
            ->assertSeeHtml('fi-tr-date-btn', false)
            ->assertSeeHtml($today, false);

        // The calendar panel: weekday headers run Monday → Sunday. Owner
        // 2026-11-20: only SUNDAY is the red weekend column (Sat is a working
        // day here), and out-of-month Sundays fade with the dim dates.
        preg_match('/fi-tr-cal-weekdays.*?<\/div>/s', (string) $response->getContent(), $m);
        $this->assertNotEmpty($m, 'the calendar weekday strip must render');
        preg_match_all('/<span(?:\s+class="([^"]*)")?>([^<]*)<\/span>/', $m[0], $spans, PREG_SET_ORDER);
        $headers = array_map(fn ($s) => ['class' => $s[1] ?? '', 'day' => $s[2]], $spans);
        $this->assertSame(
            ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            array_column($headers, 'day'),
            'calendar weeks must start Monday',
        );
        foreach ($headers as $header) {
            $this->assertSame(
                $header['day'] === 'Sun',
                str_contains($header['class'], 'weekend'),
                "the {$header['day']} header must ".($header['day'] === 'Sun' ? '' : 'not ').'be marked weekend',
            );
        }
        $page = (string) $response->getContent();
        $this->assertStringContainsString('weekend\': cell.weekend', $page, 'day cells must carry the weekend flag');
        $this->assertStringContainsString('weekend: d.getDay() === 0', $page, 'weekend = SUNDAY only (getDay 0) — not Saturday');
        $this->assertStringNotContainsString('d.getDay() === 6', $page, 'Saturday must not be counted as weekend');

        // Out-of-month (dim) Sundays: red fades to match how the dim weekdays
        // fade — CSS contract in app.css.
        $css = (string) file_get_contents(base_path('resources/css/app.css'));
        $this->assertStringContainsString('.fi-tr-cal-days .dim.weekend { color: #fca5a5;', $css, 'dim Sundays fade red like the dim weekdays fade gray');
    }

    /**
     * Owner 2026-11-19: in the collapsed rail the icons must sit on the
     * rail's horizontal centerline. Two offsets conspire against it:
     * our expanded-sidebar padding (left 0.75rem vs right 0.5rem) shifts
     * every icon 2px right, and Filament's own inline
     * `scrollbar-gutter: stable` on .fi-sidebar-nav reserves 8px of
     * scrollbar gutter on the RIGHT only — the 88px rail's icon column
     * lands 8px left of center (verified with a Playwright probe over
     * the real rendered page). The closed state neutralizes both.
     * Source-CSS contract (colors/dimensions are compiled into the
     * built asset; the pattern is SectionHeaderCornerRadiusTest).
     */
    public function test_collapsed_rail_icons_are_horizontally_centered(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            ".fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-item > a {\n        padding-left: 0.5rem !important;\n        padding-right: 0.5rem !important;\n    }",
            $css,
            'the rail must use symmetric padding so justify-center truly centers the icons',
        );
        $this->assertStringContainsString(
            ".fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-nav {\n        scrollbar-gutter: auto;\n        scrollbar-width: none;\n    }",
            $css,
            'the rail must drop Filament\'s inline scrollbar-gutter: stable — its 8px right-only gutter shifts every icon 8px left of center',
        );
    }

    /**
     * Owner 2026-11-19 (five-point design pass, point 5): the topbar is
     * BACK on desktop — main's redesign hid it (`@media ≥lg .fi-topbar
     * { display: none }`), losing the date/calendar/name widgets. The
     * global-search slot STAYS hidden (the palette + sidebar search row own
     * search), and the user menu is un-hidden so the name-beside-avatar
     * control shows again.
     * Source-CSS contract (colors/dimensions are compiled into the built
     * asset; the pattern is SectionHeaderCornerRadiusTest).
     */
    public function test_the_desktop_topbar_is_visible_again(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringNotContainsString(
            "@media (min-width: 1024px) {\n    .fi-topbar {\n        display: none !important;\n    }\n}",
            $css,
            'the desktop topbar hide rule must be gone — the owner wants the topbar back',
        );
        $this->assertStringNotContainsString(
            '.fi-topbar .fi-user-menu',
            $css,
            'the topbar user menu must not be hidden — the name-beside-avatar control lives there',
        );
        $this->assertStringContainsString(
            ".fi-topbar .fi-global-search {\n    display: none !important;\n}",
            $css,
            'the global-search slot stays hidden — the palette + sidebar search row own search',
        );
        // The DANA-blue bar makes the white brand copy readable. It referenced
        // a --tr-dana-600 token that no longer exists (the theme defines
        // --color-dana-*) — the bar painted transparent and the white text
        // went invisible on the page background.
        $this->assertStringNotContainsString('--tr-dana-', $css, 'dangling --tr-dana-* var reference — undefined tokens paint nothing');
        $this->assertStringContainsString(
            'background-color: var(--color-dana-600);',
            $css,
            'the topbar bar must paint the DANA brand blue through the live token',
        );
    }

    /**
     * Owner 2026-11-19 (points 1 + 3): the expanded sidebar header's brand
     * row — Filament's .fi-logo renders the DANA logo and the brand span
     * FLUSH (zero gap, img touches the text) and the span is unstyled
     * (16px/400). Real gap + main's brandName look (text-xl bold,
     * tracking-tight, gray-950 / white).
     */
    public function test_the_sidebar_brand_row_has_gap_and_bold_text(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            ".fi-sidebar-header .fi-logo {\n    gap: 0.625rem;\n    align-items: center;\n}",
            $css,
            'the brand row needs a real gap between logo and text',
        );
        $this->assertStringContainsString(
            ".fi-sidebar-header .fi-logo span {\n    font-size: 1.25rem;\n    line-height: 1.25rem;\n    font-weight: 700;\n    letter-spacing: -0.025em;\n    color: #030712;\n    white-space: nowrap;\n}",
            $css,
            "the brand text must carry main's brandName look (text-xl bold tracking-tight) on ONE line",
        );
        $this->assertStringContainsString(
            ".fi-sidebar.fi-sidebar-open .fi-sidebar-header {\n        padding-left: 0.75rem !important;\n        padding-right: 0.5rem !important;\n    }",
            $css,
            'px-6 + the 32px logo left ~150px and the text wrapped — nav-aligned 0.75rem inset fits it',
        );
        $this->assertStringContainsString(
            ":root.dark .fi-sidebar-header .fi-logo span {\n    color: #fff;\n}",
            $css,
            'dark mode brand text is white',
        );
    }

    /**
     * Owner 2026-11-19 (point 4 + temp-expand bug): the sidebar blends into
     * the page (main's redesign made the aside lg:bg-transparent). Give it
     * its own surface — white over the gray-50 page in light, slate-900 over
     * near-black in dark — with a hairline end border. The surface must
     * cover EVERY desktop aside INCLUDING the temp-open hover overlay: when
     * the surface scoped itself off .tr-sidebar-temp-open, the overlay kept
     * Filament's transparent background and the z-20 topbar strip showed
     * THROUGH the z-30 overlay behind the brand row. box-shadow:none stays
     * scoped to the pinned state, or it would flatten the overlay's
     * elevation shadow.
     */
    public function test_the_sidebar_surface_is_distinct_from_the_page(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            ".fi-sidebar {\n        background: #fff !important;\n        border-inline-end: 1px solid #e2e8f0;\n    }",
            $css,
            'light sidebar surface: white + hairline end border, covering the temp-open overlay too',
        );
        $this->assertStringContainsString(
            ".fi-sidebar:not(.tr-sidebar-temp-open) {\n        box-shadow: none !important;\n    }",
            $css,
            'flat shadow is pinned-only — the temp-open overlay keeps its elevation shadow',
        );
        $this->assertStringContainsString(
            ":root.dark .fi-sidebar {\n        background: #0f172a !important;\n        border-inline-end-color: #1e293b;\n    }",
            $css,
            'dark sidebar surface: slate-900 + darker hairline',
        );
    }

    /**
     * Owner 2026-11-19: "samakan ukuran font dengan yang ada di prod
     * sekarang … font yang kita pakai sebelum main sudah cukup besar" — main
     * a0c3e30 switched the panel to self-hosted Inter Variable, which reads
     * smaller than the pre-main stack at the same px. Pre-main, the panel
     * declared 'Instrument Sans' but never loaded its files (the bunny.net
     * stylesheet was welcome-page-only), so it rendered system-ui — the look
     * the owner wants back. Restore the pre-main @theme stack, drop the
     * Inter import, and remove the provider's ->font('Inter Variable') so
     * Filament's --font-family falls back to the same system stack.
     * Source-CSS/provider contract (SectionHeaderCornerRadiusTest pattern).
     */
    public function test_the_panel_font_stack_matches_the_pre_main_design(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringNotContainsString(
            "@import '@fontsource-variable/inter';",
            $css,
            'the Inter import must go — the owner wants the pre-main font back',
        );
        $this->assertStringContainsString(
            "--font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',\n        'Segoe UI Symbol', 'Noto Color Emoji';",
            $css,
            'the pre-main --font-sans stack must be restored verbatim (Instrument Sans is not loaded in the panel — it renders system-ui, the pre-main look)',
        );

        $provider = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $this->assertStringNotContainsString('->font(', $provider, "Filament's ->font('Inter Variable') must go — it re-pins --font-family to Inter");
    }

    public function test_the_user_menu_shows_the_name_beside_the_avatar(): void
    {
        $user = $this->adminUser();

        $html = (string) $this->actingAs($user)
            ->get('/admin')
            ->assertSuccessful()
            ->assertSeeHtml('fi-tr-user-name', false)
            ->assertSeeHtml(e($user->name), false)
            ->getContent();

        // Owner 2026-11-19: the name sits to the RIGHT of the avatar icon.
        $this->assertGreaterThan(
            mb_strpos($html, 'fi-user-avatar'),
            mb_strpos($html, 'fi-tr-user-name'),
            'the name must render after (right of) the avatar',
        );
    }

    private function adminUser(): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'view dashboard widgets']);
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo(['view incidents', 'view dashboard widgets']);

        return $user;
    }
}
