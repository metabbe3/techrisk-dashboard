<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\WarRoomSession;
use App\Services\WarRoom\WarRoomService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WarRoomCreateController extends Controller
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
            'deep_analysis' => 'boolean',
            'user_instructions' => 'nullable|string|max:2000',
        ]);

        $incidentIds = $validated['incident_ids'];

        if (count($incidentIds) === 1) {
            $existing = WarRoomSession::forIncident($incidentIds[0])
                ->whereIn('status', ['pending', 'running'])
                ->latestFirst()
                ->first();

            if ($existing) {
                return $this->successResponse([
                    'existing_session' => [
                        'id' => $existing->id,
                        'title' => $existing->title,
                        'status' => $existing->status,
                        'created_at' => $existing->created_at?->toIso8601String(),
                    ],
                ], 'This incident already has an active discussion.', 409);
            }
        }

        try {
            $session = $this->warRoomService->createSession(
                incidentIds: $incidentIds,
                user: $request->user(),
                selectedAgents: $validated['selected_agents'],
                maxRounds: $validated['max_rounds'] ?? 2,
                model: $validated['model'] ?? null,
                moderatorModel: $validated['moderator_model'] ?? null,
                enableWebSearch: $validated['enable_web_search'] ?? false,
                deepAnalysis: $validated['deep_analysis'] ?? true,
                userInstructions: $validated['user_instructions'] ?? null,
            );
        } catch (\RuntimeException $e) {
            // ponytail: the service throws RuntimeException as a deliberate, user-facing
            // rate-limit signal, so its (controlled) message is safe to surface at 429.
            Log::warning('War Room session rate limited', [
                'incident_ids' => $incidentIds,
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse($e->getMessage(), 429);
        }

        // Unexpected errors propagate to the global exception handler (500, unified
        // envelope, logged) — internal exception detail is never echoed to the client.

        return $this->successResponse([
            'id' => $session->id,
            'status' => $session->status,
            'title' => $session->title,
        ], 'War Room session created.', 201);
    }
}
