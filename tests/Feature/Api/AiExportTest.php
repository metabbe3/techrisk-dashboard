<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Incident;
use App\Models\Label;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AiExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $this->user->givePermissionTo('access api');
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    protected function authenticatedGetJson(string $uri, array $params = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson($uri.($params ? '?'.http_build_query($params) : ''));
    }

    // =========================================================================
    // Happy Path Tests
    // =========================================================================

    public function test_export_without_filters_returns_incidents(): void
    {
        Incident::factory()->count(3)->create();

        $response = $this->authenticatedGetJson('/api/v1/ai/export');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'Success')
            ->assertJsonPath('message', 'Incidents exported successfully.')
            ->assertJsonStructure([
                'code',
                'status',
                'message',
                'data' => [
                    'incidents' => [
                        '*' => [
                            'id', 'no', 'title', 'summary', 'root_cause', 'timeline',
                            'severity', 'incident_type', 'incident_source', 'incident_status',
                            'incident_date', 'discovered_at', 'stop_bleeding_at',
                            'entry_date_tech_risk', 'fund_status', 'potential_fund_loss',
                            'recovered_fund', 'fund_loss', 'reported_by', 'mttr', 'mtbf',
                            'pic', 'labels', 'created_at',
                        ],
                    ],
                    'pagination' => ['limit', 'offset', 'total', 'has_more'],
                ],
            ]);

        $this->assertCount(3, $response->json('data.incidents'));
    }

    public function test_export_with_limit_offset_pagination_works(): void
    {
        Incident::factory()->count(5)->create();

        // First page
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'limit' => 2,
            'offset' => 0,
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.incidents'));
        $this->assertEquals(2, $response->json('data.pagination.limit'));
        $this->assertEquals(0, $response->json('data.pagination.offset'));
        $this->assertEquals(5, $response->json('data.pagination.total'));
        $this->assertTrue($response->json('data.pagination.has_more'));

        // Second page
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'limit' => 2,
            'offset' => 2,
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.incidents'));
        $this->assertEquals(2, $response->json('data.pagination.offset'));

        // Last page
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'limit' => 2,
            'offset' => 4,
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.incidents'));
        $this->assertFalse($response->json('data.pagination.has_more'));
    }

    public function test_export_with_date_range_filter_works(): void
    {
        Incident::factory()->create(['incident_date' => '2025-01-15 10:00:00']);
        Incident::factory()->create(['incident_date' => '2025-06-15 10:00:00']);
        Incident::factory()->create(['incident_date' => '2025-12-15 10:00:00']);

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'start_date' => '2025-04-01',
            'end_date' => '2025-08-31',
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.incidents'));
        $this->assertEquals(1, $response->json('data.pagination.total'));
    }

    public function test_export_with_start_date_only_filter_works(): void
    {
        Incident::factory()->create(['incident_date' => '2025-01-15 10:00:00']);
        Incident::factory()->create(['incident_date' => '2025-06-15 10:00:00']);
        Incident::factory()->create(['incident_date' => '2025-12-15 10:00:00']);

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'start_date' => '2025-06-01',
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.incidents'));
    }

    public function test_export_with_end_date_only_filter_works(): void
    {
        Incident::factory()->create(['incident_date' => '2025-01-15 10:00:00']);
        Incident::factory()->create(['incident_date' => '2025-06-15 10:00:00']);
        Incident::factory()->create(['incident_date' => '2025-12-15 10:00:00']);

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'end_date' => '2025-06-30',
        ]);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.incidents'));
    }

    public function test_export_with_severity_filter_works(): void
    {
        Incident::factory()->create(['severity' => 'P1']);
        Incident::factory()->create(['severity' => 'P2']);
        Incident::factory()->create(['severity' => 'P3']);

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'severity' => 'P1',
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.incidents'));
        $this->assertEquals('P1', $response->json('data.incidents.0.severity'));
    }

    public function test_export_with_type_filter_works(): void
    {
        Incident::factory()->create(['incident_type' => 'Tech']);
        Incident::factory()->create(['incident_type' => 'Non-tech']);

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'type' => 'Tech',
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.incidents'));
        $this->assertEquals('Tech', $response->json('data.incidents.0.incident_type'));
    }

    public function test_export_with_combined_filters_works(): void
    {
        Incident::factory()->create([
            'severity' => 'P1',
            'incident_type' => 'Tech',
            'incident_date' => '2025-03-15 10:00:00',
        ]);
        Incident::factory()->create([
            'severity' => 'P2',
            'incident_type' => 'Tech',
            'incident_date' => '2025-03-20 10:00:00',
        ]);
        Incident::factory()->create([
            'severity' => 'P1',
            'incident_type' => 'Non-tech',
            'incident_date' => '2025-03-25 10:00:00',
        ]);

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'severity' => 'P1',
            'type' => 'Tech',
            'start_date' => '2025-01-01',
            'end_date' => '2025-06-30',
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.incidents'));
        $this->assertEquals('P1', $response->json('data.incidents.0.severity'));
        $this->assertEquals('Tech', $response->json('data.incidents.0.incident_type'));
    }

    public function test_export_response_has_correct_field_types(): void
    {
        $pic = User::factory()->create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $label = Label::create(['name' => 'database']);

        $incident = Incident::factory()->create([
            'no' => '20250115_IN_P1_001',
            'title' => 'Test Incident',
            'summary' => 'A test summary',
            'root_cause' => 'Root cause text',
            'timeline' => '10:00 - Started',
            'severity' => 'P1',
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
            'incident_status' => 'Completed',
            'incident_date' => '2025-01-15 10:30:00',
            'discovered_at' => '2025-01-15 10:32:00',
            'stop_bleeding_at' => '2025-01-15 10:35:00',
            'entry_date_tech_risk' => '2025-01-15',
            'fund_status' => 'Confirmed loss',
            'potential_fund_loss' => 15000000,
            'recovered_fund' => 5000000,
            'fund_loss' => 5000000,
            'reported_by' => 'john@example.com',
            'mttr' => 5,
            'mtbf' => 30,
            'pic_id' => $pic->id,
        ]);
        $incident->labels()->attach($label);

        $response = $this->authenticatedGetJson('/api/v1/ai/export');

        $response->assertStatus(200);

        $incidentData = $response->json('data.incidents.0');

        // Check formatted dates
        $this->assertEquals('2025-01-15T10:30:00', $incidentData['incident_date']);
        $this->assertEquals('2025-01-15T10:32:00', $incidentData['discovered_at']);
        $this->assertEquals('2025-01-15T10:35:00', $incidentData['stop_bleeding_at']);
        $this->assertEquals('2025-01-15', $incidentData['entry_date_tech_risk']);

        // Check pic is flattened with name and email
        $this->assertEquals(['name' => 'John Doe', 'email' => 'john@example.com'], $incidentData['pic']);

        // Check labels are returned as name array
        $this->assertEquals(['database'], $incidentData['labels']);

        // Check core fields
        $this->assertEquals('20250115_IN_P1_001', $incidentData['no']);
        $this->assertEquals('Test Incident', $incidentData['title']);
        $this->assertEquals('P1', $incidentData['severity']);
        $this->assertEquals('Tech', $incidentData['incident_type']);
        $this->assertEquals('Confirmed loss', $incidentData['fund_status']);
    }

    public function test_export_incidents_ordered_by_incident_date_desc(): void
    {
        Incident::factory()->create(['incident_date' => '2025-01-15 10:00:00', 'title' => 'First']);
        Incident::factory()->create(['incident_date' => '2025-06-15 10:00:00', 'title' => 'Third']);
        Incident::factory()->create(['incident_date' => '2025-03-15 10:00:00', 'title' => 'Second']);

        $response = $this->authenticatedGetJson('/api/v1/ai/export');

        $response->assertStatus(200);
        $incidents = $response->json('data.incidents');
        $this->assertEquals('Third', $incidents[0]['title']);
        $this->assertEquals('Second', $incidents[1]['title']);
        $this->assertEquals('First', $incidents[2]['title']);
    }

    public function test_export_with_incident_without_pic_returns_null_pic(): void
    {
        Incident::factory()->create(['pic_id' => null]);

        $response = $this->authenticatedGetJson('/api/v1/ai/export');

        $response->assertStatus(200);
        $this->assertNull($response->json('data.incidents.0.pic'));
    }

    public function test_export_with_incident_without_labels_returns_empty_array(): void
    {
        Incident::factory()->create();

        $response = $this->authenticatedGetJson('/api/v1/ai/export');

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('data.incidents.0.labels'));
    }

    // =========================================================================
    // Validation Tests
    // =========================================================================

    public function test_invalid_severity_returns_422(): void
    {
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'severity' => 'INVALID',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['severity']);
    }

    public function test_invalid_type_returns_422(): void
    {
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'type' => 'INVALID',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_end_date_before_start_date_returns_422(): void
    {
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'start_date' => '2025-12-31',
            'end_date' => '2025-01-01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_limit_above_1000_returns_422(): void
    {
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'limit' => 1001,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);
    }

    public function test_limit_zero_returns_422(): void
    {
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'limit' => 0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);
    }

    public function test_negative_offset_returns_422(): void
    {
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'offset' => -1,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['offset']);
    }

    public function test_non_integer_limit_returns_422(): void
    {
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'limit' => 'abc',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['limit']);
    }

    public function test_non_integer_offset_returns_422(): void
    {
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'offset' => 'abc',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['offset']);
    }

    public function test_invalid_date_format_returns_422(): void
    {
        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'start_date' => 'not-a-date',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['start_date']);
    }

    public function test_valid_severity_values_are_accepted(): void
    {
        foreach (['P1', 'P2', 'P3', 'P4', 'G', 'X1', 'X2', 'X3', 'X4', 'Non Incident'] as $severity) {
            Incident::factory()->create(['severity' => $severity]);
        }

        foreach (['P1', 'P2', 'P3', 'P4', 'G', 'X1', 'X2', 'X3', 'X4', 'Non Incident'] as $severity) {
            $response = $this->authenticatedGetJson('/api/v1/ai/export', [
                'severity' => $severity,
            ]);

            $response->assertStatus(200, "Failed for severity: {$severity}");
        }
    }

    public function test_valid_type_values_are_accepted(): void
    {
        foreach (['Tech', 'Non-tech', 'Company Loss'] as $type) {
            Incident::factory()->create(['incident_type' => $type]);
        }

        foreach (['Tech', 'Non-tech', 'Company Loss'] as $type) {
            $response = $this->authenticatedGetJson('/api/v1/ai/export', [
                'type' => $type,
            ]);

            $response->assertStatus(200, "Failed for type: {$type}");
        }
    }

    // =========================================================================
    // Authorization Tests
    // =========================================================================

    public function test_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/api/v1/ai/export');

        $response->assertStatus(401);
    }

    public function test_user_without_api_permission_returns_403(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/ai/export');

        $response->assertStatus(403);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function test_empty_results_when_no_incidents_match_filters(): void
    {
        Incident::factory()->count(3)->create(['severity' => 'P3']);

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'severity' => 'P1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'Success');
        $this->assertCount(0, $response->json('data.incidents'));
        $this->assertEquals(0, $response->json('data.pagination.total'));
        $this->assertFalse($response->json('data.pagination.has_more'));
    }

    public function test_default_limit_is_100(): void
    {
        Incident::factory()->count(5)->create();

        $response = $this->authenticatedGetJson('/api/v1/ai/export');

        $response->assertStatus(200);
        $this->assertEquals(100, $response->json('data.pagination.limit'));
    }

    public function test_default_offset_is_0(): void
    {
        Incident::factory()->count(3)->create();

        $response = $this->authenticatedGetJson('/api/v1/ai/export');

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.pagination.offset'));
    }

    public function test_pagination_has_more_is_false_when_all_fits(): void
    {
        Incident::factory()->count(3)->create();

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'limit' => 100,
        ]);

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.pagination.has_more'));
    }

    public function test_export_with_limit_1000_works(): void
    {
        Incident::factory()->count(3)->create();

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'limit' => 1000,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(1000, $response->json('data.pagination.limit'));
    }

    public function test_export_with_offset_zero_works(): void
    {
        Incident::factory()->count(3)->create();

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'offset' => 0,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, $response->json('data.pagination.offset'));
    }

    public function test_export_with_no_incidents_in_database(): void
    {
        $response = $this->authenticatedGetJson('/api/v1/ai/export');

        $response->assertStatus(200)
            ->assertJsonPath('status', 'Success');
        $this->assertCount(0, $response->json('data.incidents'));
        $this->assertEquals(0, $response->json('data.pagination.total'));
    }

    public function test_export_with_offset_beyond_results_returns_empty(): void
    {
        Incident::factory()->count(3)->create();

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'offset' => 100,
        ]);

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.incidents'));
        $this->assertEquals(3, $response->json('data.pagination.total'));
    }

    public function test_export_incident_with_nullable_datetime_fields(): void
    {
        // entry_date_tech_risk is NOT NULL in the schema, so provide a value
        // discovered_at and stop_bleeding_at are nullable datetime fields
        Incident::factory()->create([
            'discovered_at' => null,
            'stop_bleeding_at' => null,
        ]);

        $response = $this->authenticatedGetJson('/api/v1/ai/export');

        $response->assertStatus(200);
        $incidentData = $response->json('data.incidents.0');
        $this->assertNull($incidentData['discovered_at']);
        $this->assertNull($incidentData['stop_bleeding_at']);
    }

    public function test_export_company_loss_type_filter_works(): void
    {
        Incident::factory()->create(['incident_type' => 'Tech']);
        Incident::factory()->create(['incident_type' => 'Company Loss']);

        $response = $this->authenticatedGetJson('/api/v1/ai/export', [
            'type' => 'Company Loss',
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.incidents'));
        $this->assertEquals('Company Loss', $response->json('data.incidents.0.incident_type'));
    }
}
