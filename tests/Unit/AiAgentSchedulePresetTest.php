<?php

namespace Tests\Unit;

use App\Enums\AiAgentRunStatus;
use App\Models\AiAgent;
use App\Models\AiAgentRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAgentSchedulePresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_cron_from_preset_builds_expected_expressions(): void
    {
        $this->assertSame('15 9 * * *', AiAgent::cronFromPreset('daily', '09:15'));
        $this->assertSame('30 * * * *', AiAgent::cronFromPreset('hourly', '00:30'));
        $this->assertSame('0 9 * * 1', AiAgent::cronFromPreset('weekly', '09:00', 1));
        $this->assertSame('45 17 * * 5', AiAgent::cronFromPreset('weekly', '17:45', 5));
        $this->assertNull(AiAgent::cronFromPreset('manual'));
    }

    public function test_preset_from_cron_roundtrips(): void
    {
        $roundtrip = fn (string $type, ?string $time = null, ?int $weekday = null) => (new AiAgent([
            'frequency' => 'Cron',
            'cron_expression' => AiAgent::cronFromPreset($type, $time, $weekday),
        ]))->schedulePresetFromCron();

        $this->assertSame(['type' => 'daily', 'time' => '09:15', 'weekday' => 1], $roundtrip('daily', '09:15'));
        $this->assertSame(['type' => 'weekly', 'time' => '17:45', 'weekday' => 5], $roundtrip('weekly', '17:45', 5));

        $hourly = $roundtrip('hourly', '00:30');
        $this->assertSame('hourly', $hourly['type']);
        $this->assertSame('00:30', $hourly['time']);
    }

    public function test_preset_from_cron_defaults(): void
    {
        $manual = new AiAgent(['frequency' => 'Manual']);
        $this->assertSame('manual', $manual->schedulePresetFromCron()['type']);

        $blank = new AiAgent(['frequency' => 'Cron', 'cron_expression' => null]);
        $this->assertSame('manual', $blank->schedulePresetFromCron()['type']);

        // Any expression the preset builder can't produce stays editable as custom.
        $custom = new AiAgent(['frequency' => 'Cron', 'cron_expression' => '0 9 * * 1,3']);
        $preset = $custom->schedulePresetFromCron();
        $this->assertSame('custom', $preset['type']);
        $this->assertSame('09:00', $preset['time']);
    }

    public function test_prunable_covers_only_old_completed_runs(): void
    {
        $agent = AiAgent::create([
            'name' => 'Retention probe',
            'instructions' => 'x',
            'frequency' => 'Manual',
        ]);

        $makeRun = fn (?\DateTimeInterface $completed) => AiAgentRun::create([
            'agent_id' => $agent->id,
            'status' => $completed ? AiAgentRunStatus::Completed->value : AiAgentRunStatus::Running->value,
            'input' => 'x',
            'requested_at' => now()->subDays(120),
            'completed_at' => $completed,
        ]);

        $old = $makeRun(now()->subDays(100));
        $fresh = $makeRun(now());
        $oldButRunning = $makeRun(null);

        $prunable = (new AiAgentRun)->prunable()->pluck('id');

        $this->assertTrue($prunable->contains($old->id));
        $this->assertFalse($prunable->contains($fresh->id));
        $this->assertFalse($prunable->contains($oldButRunning->id));
    }
}
