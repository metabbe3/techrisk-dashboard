<?php

namespace Database\Factories;

use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;

class SkillFactory extends Factory
{
    protected $model = Skill::class;

    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'name' => $name,
            'display_name' => str($name)->replace('-', ' ')->title(),
            'description' => fake()->sentence(),
            'domain' => 'incident_management',
            'content' => "# {$name}\n\nStep 1.\nStep 2.\n",
            'frameworks' => [],
            'tags' => [],
            'difficulty' => 'intermediate',
            'is_active' => true,
            'source' => 'manual',
            'version' => 1,
            'sort_order' => fake()->numberBetween(1, 20),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
