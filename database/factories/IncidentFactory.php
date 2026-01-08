<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Incident>
 */

class IncidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $severity = $this->faker->randomElement(['P1', 'P2', 'P3', 'P4']);
        $year = $this->faker->year();
        $randomNumber = $this->faker->unique()->randomNumber(3, true); // Generates a 3-digit number

        return [
            'no' => $year . '_IN_' . $severity . '_' . str_pad($randomNumber, 3, '0', STR_PAD_LEFT),
            'title' => $this->faker->sentence,
            'summary' => $this->faker->paragraph,
            'severity' => $this->faker->randomElement(['p1', 'p2', 'p3', 'p4']),
            'incident_type' => $this->faker->randomElement(['Tech', 'Non-tech']),
            'incident_date' => $this->faker->dateTimeThisYear(),
            'entry_date_tech_risk' => $this->faker->dateTimeThisYear(),
        ];
    }
}
