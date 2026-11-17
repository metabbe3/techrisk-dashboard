<?php

namespace Tests\Feature\Ai;

use App\Enums\AiAgentEngine;
use App\Enums\AiAgentFrequency;
use App\Filament\Resources\AiAgentResource;
use App\Models\AiAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAgentScheduleMappingTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(array $overrides = []): AiAgent
    {
        return AiAgent::create(array_merge([
            'name' => 'Mapping Agent',
            'instructions' => 'Do the thing.',
            'frequency' => 'Manual',
        ], $overrides));
    }

    public function test_resolve_schedule_data_maps_once(): void
    {
        $at = '2026-11-20 09:30';

        $data = AiAgentResource::resolveScheduleData([
            'schedule_type' => 'once',
            'run_at' => $at,
        ]);

        $this->assertSame(AiAgentFrequency::Once->value, $data['frequency']);
        $this->assertNull($data['cron_expression']);
        $this->assertSame($at, $data['run_at']);
        $this->assertArrayNotHasKey('schedule_type', $data);
    }

    public function test_resolve_schedule_data_clears_run_at_on_other_types(): void
    {
        foreach (['manual', 'daily', 'custom'] as $type) {
            $data = AiAgentResource::resolveScheduleData([
                'schedule_type' => $type,
                'run_at' => '2026-11-20 09:30',
                'cron_expression' => $type === 'custom' ? '0 9 * * *' : null,
                'run_time' => '09:00',
            ]);

            $this->assertNull($data['run_at'], "run_at must not survive a {$type} schedule");
            $this->assertNotSame(AiAgentFrequency::Once->value, $data['frequency']);
        }
    }

    public function test_once_agent_round_trips_into_the_form_preset(): void
    {
        $agent = $this->makeAgent([
            'frequency' => 'Once',
            'run_at' => now()->addDay(),
        ]);

        $preset = $agent->schedulePresetFromCron();

        $this->assertSame('once', $preset['type']);
        // The form's run_at fills from the record's cast, not the preset.
        $this->assertEquals(now()->addDay()->startOfSecond(), $agent->run_at);
    }

    public function test_next_run_at_accessor_for_once(): void
    {
        // sqlite stores whole seconds — build the expectation the same way.
        $future = now()->addHours(2)->startOfSecond();
        $agent = $this->makeAgent(['frequency' => 'Once', 'run_at' => $future, 'enabled' => true]);
        $this->assertTrue($future->equalTo($agent->next_run_at), 'enabled + future run_at → due');

        $agent = $this->makeAgent(['frequency' => 'Once', 'run_at' => now()->subHour(), 'enabled' => true]);
        $this->assertNull($agent->next_run_at, 'already fired');

        $agent = $this->makeAgent(['frequency' => 'Once', 'run_at' => $future, 'enabled' => false]);
        $this->assertNull($agent->next_run_at, 'disabled');
    }

    public function test_engine_and_run_at_casts(): void
    {
        $agent = $this->makeAgent(['engine' => 'go', 'run_at' => now()->addDay()]);

        $this->assertSame(AiAgentEngine::Go, $agent->engine);
        $this->assertNotNull($agent->run_at?->format('Y-m-d'));
    }
}
