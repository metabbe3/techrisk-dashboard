<?php

namespace Database\Factories;

use App\Enums\Severity;
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
        // Canonical enum case only (edit-safety rule 1): lowercase values are
        // masked by MySQL's case-insensitive collation in prod but silently
        // fail METRIC_ELIGIBLE/enum filters under the case-sensitive SQLite
        // the test suite runs on.
        $severity = $this->faker->randomElement(Severity::METRIC_ELIGIBLE);
        $year = $this->faker->year();
        $randomNumber = $this->faker->unique()->randomNumber(3, true); // Generates a 3-digit number

        return [
            'no' => $year.'_IN_'.$severity.'_'.str_pad($randomNumber, 3, '0', STR_PAD_LEFT),
            'title' => $this->faker->sentence,
            'summary' => $this->faker->paragraph,
            'severity' => $severity,
            'classification' => $this->faker->randomElement(['Incident', 'Issue']),
            'incident_type' => $this->faker->randomElement(['Tech', 'Non-tech']),
            'incident_source' => $this->faker->randomElement(['Internal', 'External']),
            'incident_date' => $this->faker->dateTimeThisYear(),
            'entry_date_tech_risk' => $this->faker->dateTimeThisYear(),
        ];
    }
}
