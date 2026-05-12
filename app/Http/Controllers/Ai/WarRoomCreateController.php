<?php

namespace App\Http\Controllers\Ai;

use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomCreateController
{
    public function __construct(
        private WarRoomService $warRoomService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'incident_ids' => 'required|array|min:1',
            'incident_ids.*' => 'exists:incidents,id',
            'selected_agents' => 'required|array|min:1',
            'selected_agents.*' => 'string',
            'max_rounds' => 'integer|min:1|max:5',
            'model' => 'nullable|string',
            'moderator_model' => 'nullable|string',
            'enable_web_search' => 'boolean',
            'user_instructions' => 'nullable|string|max:2000',
        ]);

        $incidentIds = $validated['incident_ids'];

        if (count($incidentIds) === 1) {
            $existing = WarRoomSession::forIncident($incidentIds[0])
                ->where('status', '!=', 'failed')
                ->latestFirst()
                ->first();

            if ($existing) {
                return response()->json([
                    'message' => 'This incident already has an active discussion.',
                    'existing_session' => [
                        'id' => $existing->id,
                        'title' => $existing->title,
                        'status' => $existing->status,
                        'created_at' => $existing->created_at?->toIso8601String(),
                    ],
                ], 409);
            }
        }

        $session = $this->warRoomService->createSession(
            incidentIds: $incidentIds,
            user: $request->user(),
            selectedAgents: $validated['selected_agents'],
            maxRounds: $validated['max_rounds'] ?? 2,
            model: $validated['model'] ?? null,
            moderatorModel: $validated['moderator_model'] ?? null,
            enableWebSearch: $validated['enable_web_search'] ?? false,
            userInstructions: $validated['user_instructions'] ?? null,
        );

        return response()->json([
            'id' => $session->id,
            'status' => $session->status,
            'title' => $session->title,
        ], 201);
    }
}
