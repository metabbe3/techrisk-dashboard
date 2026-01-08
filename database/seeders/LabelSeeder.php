<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Label;

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