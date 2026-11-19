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
     * rail's horizontal centerline. The item rows inherit Filament's
     * justify-center, but our expanded-sidebar padding (left 0.75rem vs
     * right 0.5rem) shifts every icon 2px right of center — neutralize
     * it in the closed state. Source-CSS contract (colors/dimensions are
     * compiled into the built asset; the pattern is
     * SectionHeaderCornerRadiusTest).
     */
    public function test_collapsed_rail_icons_are_horizontally_centered(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            '.fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-item > a {',
            $css,
        );
        $this->assertStringContainsString(
            ".fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-item > a {\n        padding-left: 0.5rem !important;\n        padding-right: 0.5rem !important;\n    }",
            $css,
            'the rail must use symmetric padding so justify-center truly centers the icons',
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
