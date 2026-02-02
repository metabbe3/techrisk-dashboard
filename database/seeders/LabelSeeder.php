<?php

namespace Database\Seeders;

use App\Models\Label;
use Illuminate\Database\Seeder;

class LabelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $labels = [
            ['name' => 'database'],
            ['name' => 'performance'],
            ['name' => 'security'],
            ['name' => 'frontend'],
            ['name' => 'api'],
            ['name' => 'payment-gateway'],
            ['name' => 'outage'],
        ];

        foreach ($labels as $label) {
            Label::create($label);
        }
    }
}
