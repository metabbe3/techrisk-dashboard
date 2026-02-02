<?php

namespace Database\Seeders;

use App\Models\IncidentType;
use Illuminate\Database\Seeder;

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
