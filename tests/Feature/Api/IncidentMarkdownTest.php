<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class IncidentMarkdownTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');
        $this->token = $user->createToken('test-token')->plainTextToken;
    }

    public function test_incident_markdown_endpoint_returns_expected_document(): void
    {
        $incident = Incident::factory()->create([
            'title' => 'Payment Gateway Timeout',
            'summary' => 'Service was down for 5 minutes during peak.',
            'root_cause' => 'Database connection pool exhausted.',
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
            'severity' => 'P1',
            'classification' => 'Incident',
            'potential_fund_loss' => 5000000,
            'fund_loss' => 1000000,
            'reported_by' => 'Alice',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents-by-no/'.$incident->no.'/markdown');

        $response->assertStatus(200);

        $markdown = base64_decode((string) $response->json('data'), true);

        // Locks the structure produced by IncidentFormatter::toMarkdown.
        $this->assertStringContainsString('# Payment Gateway Timeout', $markdown);
        $this->assertStringContainsString('**Incident ID:** '.$incident->no, $markdown);
        $this->assertStringContainsString('## Basic Information', $markdown);
        $this->assertStringContainsString('## Summary', $markdown);
        $this->assertStringContainsString('Service was down for 5 minutes during peak.', $markdown);
        $this->assertStringContainsString('## Root Cause', $markdown);
        $this->assertStringContainsString('## Financial Impact', $markdown);
        $this->assertStringContainsString('**Potential Fund Loss:** '.number_format(5000000), $markdown);
        $this->assertStringContainsString('**Actual Fund Loss:** '.number_format(1000000), $markdown);
        $this->assertStringContainsString('*Reported by: Alice*', $markdown);
    }

    public function test_incident_markdown_endpoint_returns_404_for_missing(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/incidents-by-no/20250101_IN_P1_9999/markdown');

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Incident not found.');
    }
}
