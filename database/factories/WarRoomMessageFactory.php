<?php

namespace Database\Factories;

use App\Models\WarRoomMessage;
use App\Models\WarRoomSession;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarRoomMessageFactory extends Factory
{
    protected $model = WarRoomMessage::class;

    public function definition(): array
    {
        return [
            'session_id' => WarRoomSession::factory(),
            'round' => 1,
            'agent_role' => 'sre',
            'role' => 'assistant',
            'status' => 'pending',
            'content' => null,
            'model' => config('ai.war_room.default_model', 'SMART-MODEL'),
            'created_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'content' => fake()->paragraphs(3, true),
            'prompt_tokens' => fake()->numberBetween(500, 2000),
            'completion_tokens' => fake()->numberBetween(200, 1000),
            'total_tokens' => fake()->numberBetween(700, 3000),
            'response_time_ms' => fake()->numberBetween(1000, 10000),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Agent failed: test error',
        ]);
    }
}
