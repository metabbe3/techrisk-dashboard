<?php

namespace Tests\Browser;

use App\Models\Incident;
use App\Models\User;
use Database\Seeders\IncidentTypeSeeder;
use Database\Seeders\LabelSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class DashboardTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--seed' => false]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(IncidentTypeSeeder::class);
        $this->seed(LabelSeeder::class);

        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ])->assignRole('admin');

        $this->seedDashboardData();
    }

    /**
     * Log in as admin and navigate to the dashboard.
     */
    protected function loginAsAdmin(Browser $browser): Browser
    {
        return $browser->visit('/admin/login')
            ->waitFor('[wire\\:model="data.email"]')
            ->type('[wire\\:model="data.email"]', 'admin@example.com')
            ->type('[wire\\:model="data.password"]', 'password')
            ->press('button[type="submit"]')
            ->pause(2000)->assertPathBeginsWith('/admin');
    }

    public function test_dashboard_loads_after_login(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->assertSee('Dashboard');
        });
    }

    public function test_dashboard_shows_stats_overview_widgets(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->waitFor('.fi-wi-stats-overview-stat, .fi-stats-overview', 10)
                ->assertSee('Total Incidents')
                ->assertSee('Total Issues')
                ->assertSee('Fund Loss')
                ->assertSee('Recovered')
                ->assertSee('Last Incident')
                ->assertSee('MTTR')
                ->assertSee('MTBF');
        });
    }

    public function test_dashboard_shows_action_improvements_and_potential_fund_loss(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->waitFor('.fi-wi-stats-overview-stat, .fi-stats-overview', 10)
                ->assertSee('Pending Action Improvements')
                ->assertSee('Done Action Improvements')
                ->assertSee('Potential Fund Loss');
        });
    }

    public function test_dashboard_chart_widgets_render(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            // Wait for chart widgets to render - Filament charts use canvas elements
            $browser->waitFor('canvas', 15)
                ->assertPresent('canvas')
                ->assertSee('Monthly Incidents')
                ->assertSee('Incidents by Severity')
                ->assertSee('Incidents by Type');
        });
    }

    public function test_dashboard_additional_chart_widgets_render(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            // Scroll down to reveal additional chart widgets
            $browser->script('window.scrollTo(0, document.body.scrollHeight);');

            $browser->waitForText('Fund Loss Trend', 15)
                ->assertSee('MTTR & MTBF Trend')
                ->assertSee('Incidents by PIC')
                ->assertSee('Incidents by Label');
        });
    }

    public function test_dashboard_table_widgets_render(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            // Wait for table widgets to render
            $browser->waitForText('Open Incidents', 15)
                ->assertSee('Recent Incidents');

            // Table widgets should contain table elements
            $browser->assertPresent('table')
                ->assertPresent('thead tr')
                ->assertPresent('tbody tr, tbody');
        });
    }

    public function test_dashboard_filter_area_is_present(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            // Dashboard filter component has date picker inputs
            $browser->waitFor('input[type="text"][placeholder], .fi-input-datepicker', 10)
                ->assertPresent('input.fi-input');

            // The filter form should be present (from DashboardFilter Livewire component)
            $browser->assertPresent('form');
        });
    }

    public function test_dashboard_displays_brand_name(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->assertSee('Technical Risk Dashboard');
        });
    }

    public function test_dashboard_displays_incident_data_in_stats(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            // Stats should show numeric values for incidents
            $browser->waitFor('.fi-wi-stats-overview-stat, .fi-stats-overview', 10)
                ->assertSee('IDR');
        });
    }

    /**
     * Seed realistic dashboard data for testing.
     */
    protected function seedDashboardData(): void
    {
        $statuses = ['Open', 'In progress', 'Finalization', 'Completed'];
        $severities = ['p1', 'p2', 'p3', 'p4'];

        // Create incidents for the current year with per-record randomization
        for ($i = 0; $i < 20; $i++) {
            Incident::factory()->create([
                'classification' => 'Incident',
                'incident_status' => $statuses[array_rand($statuses)],
                'incident_source' => 'Internal',
                'incident_date' => now()->subMonths(rand(0, 5))->startOfWeek(),
                'severity' => $severities[array_rand($severities)],
            ]);
        }

        // Create a few issues with per-record randomization
        for ($i = 0; $i < 5; $i++) {
            Incident::factory()->create([
                'classification' => 'Issue',
                'incident_status' => 'Completed',
                'incident_source' => 'Internal',
                'incident_date' => now()->subMonths(rand(0, 5))->startOfWeek(),
            ]);
        }
    }
}
