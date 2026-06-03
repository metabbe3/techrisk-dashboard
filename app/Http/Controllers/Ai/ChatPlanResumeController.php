<?php

namespace App\Http\Controllers\Ai;

use App\Models\ChatMessage;
use App\Services\Ai\PlanMode\PlanModeStreamingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatPlanResumeController
{
    public function __construct(
        private PlanModeStreamingService $streamingService,
    ) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate([
            'plan_id' => 'required|string',
            'conversation_id' => 'required|string',
            'answers' => 'required|array|min:1',
            'answers.*' => 'required|string|max:500',
        ]);

        if (! config('ai.plan_mode.enabled', true) || ! config('ai.plan_mode.clarification_enabled', false)) {
            return new StreamedResponse(function () {
                echo 'event: error'."\n".'data: '.json_encode(['error' => 'Plan mode clarification is not enabled.'])."\n\n";
            }, 403, ['Content-Type' => 'text/event-stream']);
        }

        $planId = $request->input('plan_id');
        $cached = Cache::get("plan_clarification:{$planId}");

        if (! $cached) {
            return new StreamedResponse(function () {
                echo 'event: error'."\n".'data: '.json_encode(['error' => 'Clarification session expired. Please ask your question again.'])."\n\n";
            }, 410, ['Content-Type' => 'text/event-stream']);
        }

        $answers = $request->input('answers');

        ChatMessage::create([
            'conversation_id' => $cached['conversationDbId'],
            'role' => 'user',
            'content' => 'Clarification: '.implode('; ', $answers),
            'plan_id' => $planId,
            'plan_metadata' => [
                'type' => 'clarification_response',
                'questions_count' => count($answers),
            ],
            'is_plan_message' => true,
            'plan_role' => 'clarification',
            'created_at' => now(),
        ]);

        $augmentedMessage = $cached['userMessage']."\n\nClarification answers:\n";
        foreach ($answers as $i => $answer) {
            $augmentedMessage .= ($i + 1).'. '.$answer."\n";
        }

        Cache::forget("plan_clarification:{$planId}");

        return $this->streamingService->streamPlanResume($planId, $cached, $augmentedMessage);
    }
}
