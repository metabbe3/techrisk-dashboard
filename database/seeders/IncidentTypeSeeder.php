<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\IncidentType;

class IncidentTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        IncidentType::create(['name' => 'Security']);
        IncidentType::create(['name' => 'Data Loss']);
        IncidentType::create(['name' => 'Outage']);
        IncidentType::create(['name' => 'Performance']);
    }
}
