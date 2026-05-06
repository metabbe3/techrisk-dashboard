<?php

namespace Tests\Browser;

use App\Models\Incident;
use App\Models\User;
use Database\Seeders\IncidentTypeSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class IncidentsResourceTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--seed' => false]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(IncidentTypeSeeder::class);

        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ])->assignRole('admin');

        Incident::factory()->count(5)->create([
            'incident_date' => now()->subDays(rand(1, 30)),
            'entry_date_tech_risk' => now()->subDays(rand(1, 30)),
            'classification' => 'Incident',
            'incident_status' => ['Open', 'In progress', 'Finalization', 'Completed'][rand(0, 3)],
            'incident_source' => 'Internal',
        ]);
    }

    /**
     * Log in as admin via the Filament login page.
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

    public function test_incidents_list_loads(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incidents')
                ->waitFor('.fi-ta-table', 10)
                ->assertPathIs('/admin/incidents')
                ->assertSee('Incidents');
        });
    }

    public function test_table_has_visible_columns(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incidents')
                ->waitFor('.fi-ta-table', 10)
                ->assertSee('ID')
                ->assertSee('Title')
                ->assertSee('Severity')
                ->assertSee('Incident status')
                ->assertSee('Incident date');
        });
    }

    public function test_create_button_visible_and_opens_form(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incidents')
                ->waitFor('.fi-ta-table', 10)
                ->assertSee('New incident')
                ->clickLink('New incident')
                ->waitFor('.fi-form', 10)
                ->assertPathIs('/admin/incidents/create')
                ->assertSee('Core Details');
        });
    }

    public function test_create_form_has_required_fields(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incidents/create')
                ->waitFor('.fi-form', 10)
                ->assertSee('Title')
                ->assertSee('Severity')
                ->assertSee('Classification')
                ->assertSee('Area')
                ->assertSee('Occurred time')
                ->assertSee('Entry date tech risk')
                ->assertSee('Create');
        });
    }

    public function test_create_incident_with_valid_data(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incidents/create')
                ->waitFor('.fi-form', 10)
                ->type('[wire:model="data.title"]', 'Dusk Test Incident')
                ->pause(300)

                // Filament selects use Alpine.js custom dropdowns
                ->click('[wire:model="data.severity"]')
                ->waitFor('.fi-fo-select-select-option-list', 5)
                ->click('@option-P3')
                ->pause(300)

                ->click('[wire:model="data.classification"]')
                ->waitFor('.fi-fo-select-select-option-list', 5)
                ->click('@option-Incident')
                ->pause(300)

                ->click('[wire:model="data.incident_type"]')
                ->waitFor('.fi-fo-select-select-option-list', 5)
                ->click('@option-Tech')
                ->pause(300)

                ->type('[wire:model="data.incident_date"]', '2026-05-01 10:00')
                ->type('[wire:model="data.entry_date_tech_risk"]', '2026-05-01')

                ->press('Create')
                ->pause(3000)

                ->assertPathIsNot('/admin/incidents/create');
        });
    }

    public function test_view_page_shows_incident_details(): void
    {
        $incident = Incident::first();

        $this->browse(function (Browser $browser) use ($incident) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incidents/'.$incident->id)
                ->waitFor('.fi-infolist', 10)
                ->assertSee('Core Details')
                ->assertSee('Triage & Impact')
                ->assertSee($incident->title);
        });
    }

    public function test_search_and_filter_controls_visible(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incidents')
                ->waitFor('.fi-ta-table', 10)
                ->assertPresent('input.fi-input')
                ->assertPresent('.fi-ta-filter-toggle');
        });
    }

    public function test_tabs_on_list_page(): void
    {
        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incidents')
                ->waitFor('.fi-ta-table', 10)
                ->assertSee('All Cases')
                ->assertSee('On Going')
                ->assertSee('Completed Cases')

                ->clickLink('On Going')
                ->pause(1000)
                ->waitFor('.fi-ta-table', 5)

                ->clickLink('Completed Cases')
                ->pause(1000)
                ->waitFor('.fi-ta-table', 5)

                ->clickLink('All Cases')
                ->pause(1000)
                ->waitFor('.fi-ta-table', 5);
        });
    }
}
