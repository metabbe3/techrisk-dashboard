<?php

namespace Tests\Browser;

use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use Database\Seeders\IncidentTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class IssuesResourceTest extends DuskTestCase
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

        // Create test issue records (classification = 'Issue')
        $incidentTypeId = IncidentType::first()->id;

        foreach (range(1, 5) as $i) {
            Incident::factory()->create([
                'classification' => 'Issue',
                'incident_date' => now()->subDays($i * 3),
                'entry_date_tech_risk' => now()->subDays($i * 3)->format('Y-m-d'),
                'incident_type_id' => $incidentTypeId,
                'severity' => ['P1', 'P2', 'P3', 'P4'][$i % 4],
            ]);
        }
    }

    protected function loginAsAdmin(Browser $browser): Browser
    {
        return $browser->visit('/admin/login')
            ->waitFor('[wire\\:model="data.email"]')
            ->type('[wire\\:model="data.email"]', 'admin@example.com')
            ->type('[wire\\:model="data.password"]', 'password')
            ->press('button[type="submit"]')
            ->pause(2000)->assertPathBeginsWith('/admin');
    }

    public function test_issues_list_page_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/issues')
                ->pause(2000)
                ->assertPathIs('/admin/issues')
                ->assertSee('Issues');
        });
    }

    public function test_issues_table_shows_records_with_columns(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/issues')
                ->pause(2000)
                ->assertSee('ID')
                ->assertSee('Title')
                ->assertSee('Severity')
                ->assertSee('Start Date');
        });
    }

    public function test_new_issue_button_is_visible(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/issues')
                ->pause(2000)
                ->assertSee('New issue');
        });
    }

    public function test_click_new_issue_opens_create_form(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/issues')
                ->pause(2000)
                ->press('New issue')
                ->pause(1500)
                ->assertPathIs('/admin/issues/create')
                ->assertSee('Issue Details')
                ->assertSee('Issue Name')
                ->assertSee('Severity');
        });
    }

    public function test_create_issue_with_valid_data(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/issues/create')
                ->pause(2000)
                ->assertPathIs('/admin/issues/create')
                ->type('input[name="title"]', 'Test Issue from Dusk')
                ->select('select[name="severity"]', 'P2')
                ->press('button[type="submit"]')
                ->pause(3000)
                ->assertPathIs('/admin/issues');

            // Verify the issue appears in the list
            $browser->assertSee('Test Issue from Dusk');
        });
    }

    public function test_edit_page_loads_with_pre_filled_data(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            // Navigate to issues list and click the first edit button
            $browser->visit('/admin/issues')
                ->pause(2000);

            // Click the edit action (pencil icon) on the first row
            $browser->click('table tbody tr:first-child .fi-ta-edit-action-button')
                ->pause(2000)
                ->assertPathBeginsWith('/admin/issues/')
                ->assertSee('Issue Details');

            // Verify the title field is present and has a value (readOnly on edit context)
            $titleValue = $browser->inputValue('input[name="title"]');
            $this->assertNotEmpty($titleValue, 'Title field should be pre-filled on edit page');

            // Verify other form fields are present and save
            $browser->assertSee('Issue Name')
                ->assertSee('Severity')
                ->press('button[type="submit"]')
                ->pause(3000)
                ->assertPathIs('/admin/issues');
        });
    }

    public function test_import_action_button_exists(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/issues')
                ->pause(2000);

            // Look for Import button in the header actions area
            $browser->assertSee('Import');
        });
    }

    public function test_search_and_filter_controls_are_visible(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/issues')
                ->pause(2000);

            // Verify the filter toggle button exists (Filament shows filters via a toggle)
            $browser->assertPresent('.fi-ta-filters-trigger');

            // Open the filters panel
            $browser->click('.fi-ta-filters-trigger')
                ->pause(1000)
                ->assertSee('Quick Period')
                ->assertSee('Custom date range');
        });
    }
}
