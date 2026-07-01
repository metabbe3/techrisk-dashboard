<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\WarRoomAgentConfig;
use Illuminate\Http\JsonResponse;

class WarRoomAvailableAgentsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $agents = WarRoomAgentConfig::getActiveAgents()
            ->where('role_key', '!=', 'moderator')
            ->map(fn ($agent) => [
                'role_key' => $agent->role_key,
                'display_name' => $agent->display_name,
                'description' => $agent->description,
                'skills' => collect($agent->skills ?? [])
                    ->map(fn ($s) => is_array($s) ? ($s['skill'] ?? '') : $s)
                    ->filter(fn ($s) => filled($s))
                    ->values()
                    ->toArray(),
                'icon' => $agent->icon,
                'color' => $agent->color,
                'enable_web_search' => $agent->enable_web_search,
            ]);

        return $this->successResponse($agents);
    }
}
