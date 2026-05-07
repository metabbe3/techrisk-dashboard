<?php

namespace Database\Factories;

use App\Models\ActionImprovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ActionImprovement>
 */
class ActionImprovementFactory extends Factory
{
    protected $model = ActionImprovement::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence,
            'detail' => $this->faker->paragraph,
            'due_date' => now()->addDays(14),
            'pic_email' => [],
            'reminder' => true,
            'reminder_frequency' => 'weekly',
            'status' => 'pending',
        ];
    }
}
