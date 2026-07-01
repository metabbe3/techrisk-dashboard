<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\WarRoom\AgentSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomSuggestAgentsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'incident_ids' => 'required|array|min:1',
            'incident_ids.*' => 'exists:incidents,id',
            'user_instructions' => 'nullable|string|max:2000',
        ]);

        $suggested = app(AgentSuggestionService::class)->suggestAgents(
            $validated['incident_ids'],
            $validated['user_instructions'] ?? null
        );

        return $this->successResponse([
            'suggested_agents' => $suggested,
        ]);
    }
}
