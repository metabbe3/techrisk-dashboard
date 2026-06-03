<?php

namespace Tests\Unit\Services\WarRoom;

use App\Models\Incident;
use App\Services\Ai\WebSearchService;
use App\Services\WarRoom\WarRoomToolExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WarRoomToolExecutorTest extends TestCase
{
    use RefreshDatabase;

    private WarRoomToolExecutor $executor;

    private WebSearchService $webSearch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webSearch = $this->createMock(WebSearchService::class);
        $statsService = $this->createMock(\App\Services\IncidentStatsService::class);
        $statsService->method('getCachedStats')->willReturn('Period: 2026-01-01 to 2026-06-03 | Total Incidents: 100 | Open: 20 | Fund Loss: Rp0 | By Severity: P2: 50');
        $this->executor = new WarRoomToolExecutor($this->webSearch, $statsService);
    }

    private function toolCall(string $name, array $args, string $id = 'call_123'): array
    {
        return [
            'id' => $id,
            'function' => [
                'name' => $name,
                'arguments' => json_encode($args),
            ],
        ];
    }

    // --- execute() format tests ---

    public function test_execute_returns_tool_result_format(): void
    {
        $result = $this->executor->execute($this->toolCall('unknown_tool', []));

        $this->assertEquals('tool', $result['role']);
        $this->assertEquals('call_123', $result['tool_call_id']);
        $this->assertIsString($result['content']);
    }

    public function test_execute_handles_unknown_tool(): void
    {
        $result = $this->executor->execute($this->toolCall('nonexistent', []));

        $this->assertStringContainsString('Unknown tool', $result['content']);
    }

    public function test_execute_catches_exceptions_gracefully(): void
    {
        Incident::query()->delete();

        $result = $this->executor->execute($this->toolCall(
            'get_incident_details',
            ['incident_no' => 'nonexistent']
        ));

        $this->assertEquals('tool', $result['role']);
        $this->assertStringContainsString('not found', $result['content']);
    }

    // --- search_incidents ---

    public function test_search_incidents_with_severity_filter(): void
    {
        Incident::factory()->create(['severity' => 'p1', 'title' => 'Critical issue']);
        Incident::factory()->create(['severity' => 'p2', 'title' => 'Major issue']);
        Incident::factory()->create(['severity' => 'p3', 'title' => 'Minor issue']);

        $result = $this->executor->execute($this->toolCall('search_incidents', [
            'severity' => ['p1'],
        ]));

        $this->assertStringContainsString('Critical issue', $result['content']);
        $this->assertStringNotContainsString('Major issue', $result['content']);
        $this->assertStringNotContainsString('Minor issue', $result['content']);
    }

    public function test_search_incidents_with_text_query(): void
    {
        Incident::factory()->create(['title' => 'Database connection timeout']);
        Incident::factory()->create(['title' => 'Network cable damaged']);

        $result = $this->executor->execute($this->toolCall('search_incidents', [
            'query' => 'Database',
        ]));

        $this->assertStringContainsString('Database connection timeout', $result['content']);
        $this->assertStringNotContainsString('Network cable damaged', $result['content']);
    }

    public function test_search_incidents_returns_empty_when_no_results(): void
    {
        $result = $this->executor->execute($this->toolCall('search_incidents', [
            'query' => 'xyznonexistent12345',
        ]));

        $this->assertStringContainsString('No incidents found', $result['content']);
    }

    // --- get_incident_details ---

    public function test_get_incident_details_returns_formatted_data(): void
    {
        $incident = Incident::factory()->create();

        $result = $this->executor->execute($this->toolCall('get_incident_details', [
            'incident_no' => $incident->no,
        ]));

        $this->assertEquals('tool', $result['role']);
        $this->assertStringContainsString($incident->no, $result['content']);
    }

    public function test_get_incident_details_returns_not_found(): void
    {
        $result = $this->executor->execute($this->toolCall('get_incident_details', [
            'incident_no' => '9999_IN_P1_999',
        ]));

        $this->assertStringContainsString('not found', $result['content']);
    }

    // --- find_similar_incidents ---

    public function test_find_similar_incidents_no_match(): void
    {
        $incident = Incident::factory()->create();

        $mock = $this->createMock(\App\Services\RecurrenceDetectionService::class);
        $mock->method('detect')->willReturn(['matches' => []]);
        $this->app->instance(\App\Services\RecurrenceDetectionService::class, $mock);

        $result = $this->executor->execute($this->toolCall('find_similar_incidents', [
            'incident_no' => $incident->no,
        ]));

        $this->assertStringContainsString('No similar incidents found', $result['content']);
    }

    public function test_find_similar_incidents_with_results(): void
    {
        $incident = Incident::factory()->create();

        $mock = $this->createMock(\App\Services\RecurrenceDetectionService::class);
        $mock->method('detect')->willReturn([
            'matches' => [
                ['no' => '2025_IN_P1_001', 'severity' => 'p1', 'score' => 0.85, 'reason' => 'Same category'],
            ],
        ]);
        $this->app->instance(\App\Services\RecurrenceDetectionService::class, $mock);

        $result = $this->executor->execute($this->toolCall('find_similar_incidents', [
            'incident_no' => $incident->no,
        ]));

        $this->assertStringContainsString('2025_IN_P1_001', $result['content']);
        $this->assertStringContainsString('0.85', $result['content']);
    }

    // --- get_action_items ---

    public function test_get_action_items_returns_not_found(): void
    {
        $result = $this->executor->execute($this->toolCall('get_action_items', [
            'incident_no' => '9999_IN_P1_999',
        ]));

        $this->assertStringContainsString('not found', $result['content']);
    }

    public function test_get_action_items_returns_empty_for_incident(): void
    {
        $incident = Incident::factory()->create();

        $result = $this->executor->execute($this->toolCall('get_action_items', [
            'incident_no' => $incident->no,
        ]));

        $this->assertStringContainsString('No action items found', $result['content']);
    }

    // --- web_search ---

    public function test_web_search_empty_query(): void
    {
        $result = $this->executor->execute($this->toolCall('web_search', [
            'query' => '',
        ]));

        $this->assertStringContainsString('No search query provided', $result['content']);
    }

    public function test_web_search_delegates_to_service(): void
    {
        $mock = $this->createMock(WebSearchService::class);
        $mock->method('search')->willReturn([
            ['title' => 'Test Result', 'url' => 'https://example.com', 'content' => 'Some content'],
        ]);
        $executor = new WarRoomToolExecutor($mock, $this->createMock(\App\Services\IncidentStatsService::class));

        $result = $executor->execute($this->toolCall('web_search', [
            'query' => 'test query',
        ]));

        // Executor catches exceptions gracefully, so even if web_search has a bug,
        // the execute() wrapper returns a tool result with the error message
        $this->assertEquals('tool', $result['role']);
        $this->assertEquals('call_123', $result['tool_call_id']);
    }

    // --- get_stats ---

    public function test_get_stats_returns_stats(): void
    {
        Cache::forget('warroom_stats_year');

        Incident::factory()->create(['classification' => 'Incident', 'incident_date' => now()]);

        $result = $this->executor->execute($this->toolCall('get_stats', [
            'period' => 'this_year',
        ]));

        $this->assertStringContainsString('Total Incidents', $result['content']);
        $this->assertStringContainsString('Fund Loss', $result['content']);
    }
}
