<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\WarRoomAgentConfig;
use Illuminate\Http\JsonResponse;

class ChatAgentsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $agents = WarRoomAgentConfig::with(['skillRecords' => fn ($q) => $q->where('is_active', true)])
            ->active()
            ->ordered()
            ->get()
            ->where('role_key', '!=', 'moderator')
            ->map(fn ($agent) => [
                'role_key' => $agent->role_key,
                'display_name' => $agent->display_name,
                'description' => $agent->description,
                'skills' => $agent->skillRecords
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

        return $this->successResponse($agents);
    }
}
