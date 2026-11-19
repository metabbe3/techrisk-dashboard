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

        // The calendar panel: weekday headers run Monday → Sunday.
        preg_match('/fi-tr-cal-weekdays.*?<\/div>/s', (string) $response->getContent(), $m);
        $this->assertNotEmpty($m, 'the calendar weekday strip must render');
        preg_match_all('/<span>([^<]*)<\/span>/', $m[0], $spans);
        $this->assertSame(
            ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            $spans[1],
            'calendar weeks must start Monday',
        );
    }

    public function test_the_user_menu_shows_the_name_beside_the_avatar(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->get('/admin')
            ->assertSuccessful()
            ->assertSeeHtml('fi-tr-user-name', false)
            ->assertSeeHtml(e($user->name), false);
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
