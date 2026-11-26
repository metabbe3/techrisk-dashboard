<?php

namespace Database\Factories;

use App\Models\DohaTicket;
use App\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DohaTicket>
 */
class DohaTicketFactory extends Factory
{
    protected $model = DohaTicket::class;

    public function linked(Incident $incident): static
    {
        return $this->state(fn () => ['incident_id' => $incident->id]);
    }

    public function unlinked(): static
    {
        return $this->state(fn () => ['incident_id' => null]);
    }

    public function definition(): array
    {
        return [
            'doha_id' => (string) Str::uuid(),
            'ref' => 'INC-'.$this->faker->unique()->numberBetween(1, 999),
            'title' => $this->faker->sentence,
            'bank' => $this->faker->randomElement(['BCA', 'BRI', 'Mandiri', 'BNI']),
            'domain' => $this->faker->randomElement(['Channel', 'Merchant', 'Aggregator', 'All']),
            'team' => 'Tech Risk',
            'category' => 'Fund Loss',
            'status' => 'Ongoing',
            'priority' => $this->faker->randomElement(['Critical', 'High', 'Medium', 'Low']),
            'transaction_date' => $this->faker->date(),
            'transaction_time' => '08:30',
            'potential_loss' => $this->faker->randomFloat(2, 100000, 500000000),
            'doha_created_at' => $this->faker->dateTimeThisYear()->format('Y-m-d H:i:s'),
            'doha_updated_at' => $this->faker->dateTimeThisYear()->format('Y-m-d H:i:s'),
        ];
    }
}
