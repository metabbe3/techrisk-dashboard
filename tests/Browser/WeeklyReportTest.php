<?php

namespace Tests\Browser;

use App\Models\User;
use App\Models\Incident;
use App\Models\IncidentType;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\IncidentTypeSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WeeklyReportTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations for each test (SQLite in-memory is fresh each time)
        Artisan::call('migrate:fresh', ['--seed' => false]);

        // Seed roles and permissions
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(IncidentTypeSeeder::class);

        // Create admin user
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ])->assignRole('admin');

        // Get current year for test data
        $currentYear = (int) date('Y');

        // Create test incidents spread throughout the current year
        Incident::factory()->count(30)->create([
            'incident_date' => now()->subMonths(rand(1, 6))->startOfWeek(),
            'classification' => 'Incident',
            'incident_status' => ['Open', 'In progress', 'Finalization', 'Completed'][rand(0, 3)],
            'incident_source' => 'Internal',
        ]);

        // Create some incidents for last year to test year filter
        Incident::factory()->count(5)->create([
            'incident_date' => now()->subYear()->startOfWeek(),
            'classification' => 'Incident',
            'incident_status' => 'Completed',
            'incident_source' => 'Internal',
        ]);
    }

    public function test_weekly_report_loads_successfully()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin')
                ->waitFor('input[name="email"]')
                ->type('input[name="email"]', 'admin@example.com')
                ->type('input[name="password"]', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin')
                ->assertPathIs('/admin')

                ->visit('/admin/weekly-report')
                ->pause(2000) // Wait for page to load
                ->assertSee('Weekly Incident Report')
                ->assertSee('Total Open')
                ->assertSee('Total Closed')
                ->assertSee('Grand Total');
        });
    }

    public function test_year_filter_works()
    {
        $currentYear = (string) date('Y');
        $lastYear = (string) (date('Y') - 1);

        $this->browse(function (Browser $browser) use ($currentYear, $lastYear) {
            $browser->visit('/admin')
                ->waitFor('input[name="email"]')
                ->type('input[name="email"]', 'admin@example.com')
                ->type('input[name="password"]', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin')

                ->visit('/admin/weekly-report')
                ->pause(2000) // Wait for initial page load

                // Test selecting current year
                ->select('select[name="selectedYear"]', $currentYear)
                ->pause(1000) // Wait for data refresh
                ->assertSee($currentYear)

                // Test selecting last year
                ->select('select[name="selectedYear"]', $lastYear)
                ->pause(1000) // Wait for data refresh
                ->assertSee($lastYear);
        });
    }

    public function test_table_displays_weekly_data()
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/admin')
                ->waitFor('input[name="email"]')
                ->type('input[name="email"]', 'admin@example.com')
                ->type('input[name="password"]', 'password')
                ->press('button[type="submit"]')
                ->waitForLocation('/admin')

                ->visit('/admin/weekly-report')
                ->pause(2000) // Wait for page to load

                // Check that table has data rows (should have W1, W2, etc.)
                ->assertSee('W1')
                ->assertSee('Jan')
                ->assertSee('Incident Open')
                ->assertSee('Incident Closed')
                ->assertSee('Total');
        });
    }
}
