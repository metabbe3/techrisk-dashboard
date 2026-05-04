<?php

namespace Database\Factories;

use App\Models\Incident;
use App\Models\InvestigationDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\InvestigationDocument>
 */
class InvestigationDocumentFactory extends Factory
{
    protected $model = InvestigationDocument::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'file_path' => 'documents/'.$this->faker->uuid.'.pdf',
            'description' => $this->faker->optional()->sentence(),
            'pic_status' => $this->faker->optional()->randomElement(['Pending', 'Reviewed', 'Approved']),
            'original_filename' => $this->faker->word.'.pdf',
        ];
    }
}
