<?php

namespace Tests\Unit\Services\WarRoom;

use App\Services\WarRoom\WarRoomToolRegistry;
use PHPUnit\Framework\TestCase;

class WarRoomToolRegistryTest extends TestCase
{
    private WarRoomToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new WarRoomToolRegistry;
    }

    public function test_get_tool_definitions_returns_all_6_tools(): void
    {
        $definitions = $this->registry->getToolDefinitions();

        $this->assertCount(6, $definitions);

        foreach ($definitions as $definition) {
            $this->assertArrayHasKey('type', $definition);
            $this->assertSame('function', $definition['type']);
            $this->assertArrayHasKey('function', $definition);
            $this->assertArrayHasKey('name', $definition['function']);
            $this->assertArrayHasKey('description', $definition['function']);
            $this->assertArrayHasKey('parameters', $definition['function']);
        }
    }

    public function test_get_tool_definitions_filters_by_enabled_tools(): void
    {
        $enabledTools = ['search_incidents', 'get_stats'];
        $definitions = $this->registry->getToolDefinitions($enabledTools);

        $this->assertCount(2, $definitions);

        $names = array_map(fn (array $def) => $def['function']['name'], $definitions);
        $this->assertContains('search_incidents', $names);
        $this->assertContains('get_stats', $names);
    }

    public function test_get_tool_definitions_returns_all_when_null(): void
    {
        $definitions = $this->registry->getToolDefinitions(null);

        $this->assertCount(6, $definitions);
    }

    public function test_get_tool_definitions_empty_array_returns_empty(): void
    {
        $definitions = $this->registry->getToolDefinitions([]);

        $this->assertIsArray($definitions);
        $this->assertEmpty($definitions);
    }

    public function test_get_all_tool_names_returns_expected_list(): void
    {
        $names = $this->registry->getAllToolNames();

        $this->assertCount(6, $names);

        $expected = [
            'search_incidents',
            'get_incident_details',
            'find_similar_incidents',
            'get_action_items',
            'web_search',
            'get_stats',
        ];

        $this->assertSame($expected, $names);
    }

    public function test_search_incidents_has_correct_parameters(): void
    {
        $definitions = $this->registry->getToolDefinitions(['search_incidents']);
        $tool = $definitions[0];

        $this->assertSame('search_incidents', $tool['function']['name']);

        $properties = $tool['function']['parameters']['properties'];
        $expectedParams = ['severity', 'status', 'date_from', 'date_to', 'query', 'limit'];

        foreach ($expectedParams as $param) {
            $this->assertArrayHasKey($param, $properties, "Missing parameter: {$param}");
        }

        $this->assertSame('array', $properties['severity']['type']);
        $this->assertSame('array', $properties['status']['type']);
        $this->assertSame('string', $properties['date_from']['type']);
        $this->assertSame('string', $properties['date_to']['type']);
        $this->assertSame('string', $properties['query']['type']);
        $this->assertSame('integer', $properties['limit']['type']);

        $this->assertEmpty($tool['function']['parameters']['required']);
    }

    public function test_get_incident_details_has_required_parameter(): void
    {
        $definitions = $this->registry->getToolDefinitions(['get_incident_details']);
        $tool = $definitions[0];

        $this->assertSame('get_incident_details', $tool['function']['name']);

        $required = $tool['function']['parameters']['required'];
        $this->assertContains('incident_no', $required);

        $properties = $tool['function']['parameters']['properties'];
        $this->assertArrayHasKey('incident_no', $properties);
        $this->assertSame('string', $properties['incident_no']['type']);
    }

    public function test_web_search_has_required_parameter(): void
    {
        $definitions = $this->registry->getToolDefinitions(['web_search']);
        $tool = $definitions[0];

        $this->assertSame('web_search', $tool['function']['name']);

        $required = $tool['function']['parameters']['required'];
        $this->assertContains('query', $required);

        $properties = $tool['function']['parameters']['properties'];
        $this->assertArrayHasKey('query', $properties);
        $this->assertSame('string', $properties['query']['type']);
    }

    public function test_get_stats_has_period_enum(): void
    {
        $definitions = $this->registry->getToolDefinitions(['get_stats']);
        $tool = $definitions[0];

        $this->assertSame('get_stats', $tool['function']['name']);

        $properties = $tool['function']['parameters']['properties'];
        $this->assertArrayHasKey('period', $properties);

        $period = $properties['period'];
        $this->assertSame('string', $period['type']);
        $this->assertArrayHasKey('enum', $period);
        $this->assertSame(['this_month', 'this_quarter', 'this_year'], $period['enum']);

        $this->assertEmpty($tool['function']['parameters']['required']);
    }
}
