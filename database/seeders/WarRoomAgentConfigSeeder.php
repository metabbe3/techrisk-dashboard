<?php

namespace Database\Seeders;

use App\Models\WarRoomAgentConfig;
use App\Services\WarRoom\AgentPromptBuilder;
use Illuminate\Database\Seeder;

class WarRoomAgentConfigSeeder extends Seeder
{
    public function run(): void
    {
        $agents = AgentPromptBuilder::getDefaultAgents();

        foreach ($agents as $agent) {
            WarRoomAgentConfig::updateOrCreate(
                ['role_key' => $agent['role_key']],
                [
                    'display_name' => $agent['display_name'],
                    'description' => $agent['description'] ?? null,
                    'skills' => $agent['skills'] ?? null,
                    'icon' => $agent['icon'],
                    'color' => $agent['color'],
                    'system_prompt' => $agent['system_prompt'],
                    'enable_web_search' => $agent['enable_web_search'],
                    'sort_order' => $agent['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        $this->command->info('Discussion Forum agent configs seeded successfully.');
    }
}
