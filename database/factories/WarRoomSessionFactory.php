<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\User;
use App\Models\WarRoomSession;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarRoomSessionFactory extends Factory
{
    protected $model = WarRoomSession::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'incident_id' => Incident::factory(),
            'title' => fake()->sentence(),
            'status' => 'pending',
            'current_round' => 0,
            'max_rounds' => 2,
            'model' => config('ai.war_room.default_model', 'SMART-MODEL'),
            'moderator_model' => config('ai.war_room.moderator_model', 'SMART-MODEL'),
            'enable_web_search' => false,
            'deep_analysis' => true,
            'selected_agents' => ['sre', 'tech_risk', 'dba'],
            'incident_context' => ['Sample incident context data'],
            'context_summarized' => false,
            'selected_skills' => [],
            'user_instructions' => null,
            'tokens_used' => 0,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'started_at' => now()->subMinutes(10),
            'completed_at' => now(),
            'final_report' => [
                'summary' => 'Test report summary',
                'root_cause_analysis' => 'Test root cause',
            ],
            'final_report_html' => '# Test Report\n\nSummary content here.',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'started_at' => now()->subMinutes(5),
            'failed_at' => now(),
            'error_message' => 'Test error message',
        ]);
    }
}
