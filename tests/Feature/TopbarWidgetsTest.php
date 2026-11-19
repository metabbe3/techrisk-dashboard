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

        // The calendar panel: weekday headers run Monday → Sunday, and the
        // weekend columns (Sat, Sun) are marked red.
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
                in_array($header['day'], ['Sat', 'Sun'], true),
                str_contains($header['class'], 'weekend'),
                "the {$header['day']} header must ".(in_array($header['day'], ['Sat', 'Sun'], true) ? '' : 'not ').'be marked weekend',
            );
        }
        $this->assertStringContainsString('weekend\': cell.weekend', (string) $response->getContent(), 'day cells must carry the weekend flag');
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
            "the topbar bar must paint the DANA brand blue through the live token",
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
     * Owner 2026-11-19 (point 4): the sidebar blends into the page (main's
     * redesign made the aside lg:bg-transparent). Give it its own surface —
     * white over the gray-50 page in light, slate-900 over near-black in
     * dark — with a hairline end border. The temp-open hover overlay keeps
     * its shadow (the rule scopes itself :not(.tr-sidebar-temp-open), or the
     * !important background/shadow rule would flatten the overlay).
     */
    public function test_the_sidebar_surface_is_distinct_from_the_page(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            ".fi-sidebar:not(.tr-sidebar-temp-open) {\n        background: #fff !important;\n        border-inline-end: 1px solid #e2e8f0;\n        box-shadow: none !important;\n    }",
            $css,
            'light sidebar surface: white + hairline end border, scoped off the temp-open overlay',
        );
        $this->assertStringContainsString(
            ":root.dark .fi-sidebar:not(.tr-sidebar-temp-open) {\n        background: #0f172a !important;\n        border-inline-end-color: #1e293b;\n    }",
            $css,
            'dark sidebar surface: slate-900 + darker hairline',
        );
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
