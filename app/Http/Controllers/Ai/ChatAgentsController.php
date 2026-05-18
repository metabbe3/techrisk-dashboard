<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomAgentConfig;
use Illuminate\Http\JsonResponse;

class ChatAgentsController
{
    public function __invoke(): JsonResponse
    {
        $agents = WarRoomAgentConfig::getActiveAgents()
            ->where('role_key', '!=', 'moderator')
            ->map(fn ($agent) => [
                'role_key' => $agent->role_key,
                'display_name' => $agent->display_name,
                'description' => $agent->description,
                'skills' => $agent->skillRecords()->where('is_active', true)->get()
                    ->map(fn ($skill) => [
                        'name' => $skill->name,
                        'display_name' => $skill->display_name,
                        'domain' => $skill->domain,
                    ])
                    ->values()
                    ->toArray(),
                'icon' => $agent->icon,
                'color' => $agent->color,
            ]);

        return response()->json($agents);
    }
}
