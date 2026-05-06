<?php

namespace Tests\Browser;

use App\Models\ApiAuditLog;
use App\Models\IncidentType;
use App\Models\Label;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class SettingsResourcesTest extends DuskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations for each test (fresh database)
        Artisan::call('migrate:fresh', ['--seed' => false]);

        // Seed roles and permissions
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create admin user
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ])->assignRole('admin');
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

    // --------------------------------------------------------------------------
    // Incident Types
    // --------------------------------------------------------------------------

    /**
     * Test that the Incident Types list page loads with a table.
     */
    public function test_incident_types_list_loads(): void
    {
        // Seed an incident type so the table has data
        IncidentType::create(['name' => 'Security']);

        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incident-types')
                ->waitFor('table', 10)
                ->assertSee('Security');
        });
    }

    /**
     * Test that "New incident type" button is visible, opens the create form,
     * and submitting a unique name creates a new incident type.
     */
    public function test_create_incident_type(): void
    {
        $uniqueName = 'Dusk Type '.substr(md5((string) time()), 0, 8);

        $this->browse(function (Browser $browser) use ($uniqueName) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incident-types')
                ->waitFor('table', 10)
                ->assertSee('New incident type')
                ->clickLink('New incident type')
                ->waitFor('input[name="name"]', 10)
                ->type('input[name="name"]', $uniqueName)
                ->press('Create')
                ->waitFor('table', 10)
                ->assertSee($uniqueName);
        });
    }

    /**
     * Test that editing an incident type loads the pre-filled name and saving
     * the modification persists the change.
     */
    public function test_edit_incident_type(): void
    {
        $originalName = 'EditTest '.substr(md5((string) time()), 0, 8);
        $updatedName = $originalName.' Updated';

        $incidentType = IncidentType::create(['name' => $originalName]);

        $this->browse(function (Browser $browser) use ($incidentType, $originalName, $updatedName) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/incident-types/'.$incidentType->id.'/edit')
                ->waitFor('input[name="name"]', 10)
                ->assertInputValue('input[name="name"]', $originalName)
                ->clear('input[name="name"]')
                ->type('input[name="name"]', $updatedName)
                ->press('Save changes')
                ->waitFor('table', 10)
                ->assertSee($updatedName);
        });
    }

    // --------------------------------------------------------------------------
    // Labels
    // --------------------------------------------------------------------------

    /**
     * Test that the Labels list page loads with a table.
     */
    public function test_labels_list_loads(): void
    {
        Label::create(['name' => 'database']);

        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/labels')
                ->waitFor('table', 10)
                ->assertSee('database');
        });
    }

    /**
     * Test that "New label" button is visible, opens the create form,
     * and submitting a unique name creates a new label.
     */
    public function test_create_label(): void
    {
        $uniqueName = 'Dusk Label '.substr(md5((string) time()), 0, 8);

        $this->browse(function (Browser $browser) use ($uniqueName) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/labels')
                ->waitFor('table', 10)
                ->assertSee('New label')
                ->clickLink('New label')
                ->waitFor('input[name="name"]', 10)
                ->type('input[name="name"]', $uniqueName)
                ->press('Create')
                ->waitFor('table', 10)
                ->assertSee($uniqueName);
        });
    }

    /**
     * Test that editing a label loads the pre-filled name and that the Audits
     * relation manager tab is visible on the edit page.
     */
    public function test_edit_label_shows_audits_relation_manager(): void
    {
        $labelName = 'AuditLabel '.substr(md5((string) time()), 0, 8);
        $label = Label::create(['name' => $labelName]);

        $this->browse(function (Browser $browser) use ($label, $labelName) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/labels/'.$label->id.'/edit')
                ->waitFor('input[name="name"]', 10)
                ->assertInputValue('input[name="name"]', $labelName)
                ->assertSee('Audits');
        });
    }

    // --------------------------------------------------------------------------
    // API Audit Logs
    // --------------------------------------------------------------------------

    /**
     * Test that the API Audit Logs list page loads with a table.
     */
    public function test_api_audit_logs_list_loads(): void
    {
        $this->seedApiAuditLog();

        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/api-audit-logs')
                ->waitForText('API Audit Logs', 10)
                ->assertSee('API Audit Logs');
        });
    }

    /**
     * Test that API Audit Logs have filters for method, path (endpoint),
     * and status code (response_status).
     */
    public function test_api_audit_logs_have_filters(): void
    {
        $this->seedApiAuditLog();

        $this->browse(function (Browser $browser) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/api-audit-logs')
                ->waitForText('API Audit Logs', 10)
                ->click('@filters-toggle')
                ->waitFor('.fi-tbl-filters', 10)
                ->assertSee('Method')
                ->assertSee('Status');
        });
    }

    /**
     * Test that clicking the view action on an audit log row opens an
     * infolist modal showing the record details.
     */
    public function test_api_audit_log_view_shows_details(): void
    {
        $log = $this->seedApiAuditLog();

        $this->browse(function (Browser $browser) use ($log) {
            $this->loginAsAdmin($browser);

            $browser->visit('/admin/api-audit-logs')
                ->waitForText('API Audit Logs', 10)
                ->waitFor('table', 10)
                ->click('@view-'.$log->id.'-action-button')
                ->waitForText('Request Details', 10)
                ->assertSee('Request Details')
                ->assertSee('Response Details');
        });
    }

    // --------------------------------------------------------------------------
    // Helpers
    // --------------------------------------------------------------------------

    /**
     * Create a sample API audit log record for testing.
     */
    private function seedApiAuditLog(): ApiAuditLog
    {
        return ApiAuditLog::create([
            'id' => (string) Str::uuid(),
            'trace_id' => (string) Str::uuid(),
            'request_id' => (string) Str::uuid(),
            'request_timestamp' => now(),
            'method' => 'GET',
            'endpoint' => '/api/v1/incidents',
            'query_params' => ['page' => 1],
            'request_body' => null,
            'user_id' => 1,
            'user_email' => 'admin@example.com',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'DuskTest/1.0',
            'response_timestamp' => now()->addMilliseconds(150),
            'response_status' => 200,
            'response_time_ms' => 150,
            'response_size_bytes' => 2048,
            'response_data' => null,
            'error_message' => null,
            'environment' => 'local',
            'app_version' => '1.0.0',
            'metadata' => null,
        ]);
    }
}
