<?php

namespace App\Http\Controllers\Ai;

use App\Services\Ai\PlanMode\PlanModeStreamingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatPlanStreamController
{
    public function __construct(
        private PlanModeStreamingService $streamingService,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate([
            'message' => 'required|string|max:5000',
            'conversation_id' => 'nullable|uuid',
            'model' => 'nullable|string',
            'mode' => 'required|in:plan',
            'referenced_incidents' => 'nullable|array',
            'referenced_incidents.*' => 'string',
            'personas' => 'nullable|array',
            'personas.*' => 'string|exists:war_room_agent_configs,role_key',
            'web_search' => 'nullable|boolean',
        ]);

        if (! config('ai.plan_mode.enabled', true)) {
            return new StreamedResponse(function () {
                echo 'event: error'."\n".'data: '.json_encode(['error' => 'Plan mode is currently disabled.'])."\n\n";
            }, 403, ['Content-Type' => 'text/event-stream']);
        }

        return $this->streamingService->streamPlanResponse($request);
    }
}
