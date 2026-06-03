<?php

namespace Database\Factories;

use App\Models\WarRoomAgentConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

class WarRoomAgentConfigFactory extends Factory
{
    protected $model = WarRoomAgentConfig::class;

    public function definition(): array
    {
        return [
            'role_key' => fake()->unique()->word(),
            'display_name' => fake()->name(),
            'description' => fake()->sentence(),
            'skills' => ['Analysis', 'Investigation'],
            'icon' => 'heroicon-o-user',
            'color' => fake()->randomElement(['blue', 'green', 'red', 'amber', 'purple', 'indigo']),
            'system_prompt' => 'You are a test agent. Analyze the incident data and provide findings.',
            'model_override' => null,
            'enable_web_search' => false,
            'enabled_tools' => null,
            'sort_order' => fake()->numberBetween(1, 20),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
