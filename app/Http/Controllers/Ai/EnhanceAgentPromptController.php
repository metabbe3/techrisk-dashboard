<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EnhanceAgentPromptController extends Controller
{
    public function __construct(
        private readonly AiTextService $aiService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => 'required|string|in:enhance_prompt,suggest_skills',
            'text' => 'nullable|string|max:10000',
            'agent_name' => 'nullable|string|max:100',
            'agent_role' => 'nullable|string|max:50',
            'model' => 'nullable|string',
        ]);

        return $validated['action'] === 'suggest_skills'
            ? $this->suggestSkills($validated)
            : $this->enhancePrompt($validated);
    }

    private function enhancePrompt(array $validated): JsonResponse
    {
        if (blank($validated['text'] ?? null)) {
            return response()->json([
                'success' => false,
                'error' => 'Please write a draft prompt before enhancing.',
            ]);
        }

        $context = $this->buildAgentContext($validated);

        $result = $this->aiService->enhance(
            text: $validated['text'],
            fieldType: 'agent_prompt_enhance',
            model: $validated['model'] ?? null,
            additionalPrompt: $context,
        );

        return response()->json([
            'success' => $result->success,
            'text' => $result->text,
            'error' => $result->error,
            'model' => $result->model,
        ]);
    }

    private function suggestSkills(array $validated): JsonResponse
    {
        $prompt = config('ai.prompts.agent_skill_suggest');

        if (! $prompt) {
            return response()->json([
                'success' => false,
                'error' => 'Skill suggestion is not configured.',
            ]);
        }

        $userMessage = $this->buildSkillRequestMessage($validated);

        $result = $this->aiService->suggestSkills(
            userMessage: $userMessage,
            model: $validated['model'] ?? null,
        );

        return response()->json($result);
    }

    private function buildAgentContext(array $validated): ?string
    {
        $parts = [];

        if (filled($validated['agent_name'] ?? null)) {
            $parts[] = "Agent name: {$validated['agent_name']}";
        }

        if (filled($validated['agent_role'] ?? null)) {
            $parts[] = "Agent role/domain: {$validated['agent_role']}";
        }

        return empty($parts) ? null : implode('. ', $parts);
    }

    private function buildSkillRequestMessage(array $validated): string
    {
        $message = 'Suggest actionable skill capabilities for an AI Retrospective analyst agent.';

        if (filled($validated['agent_name'] ?? null)) {
            $message .= "\n\nAgent name: {$validated['agent_name']}";
        }

        if (filled($validated['agent_role'] ?? null)) {
            $message .= "\nAgent role/domain: {$validated['agent_role']}";
        }

        if (filled($validated['text'] ?? null)) {
            $message .= "\n\nCurrent system prompt context:\n".Str::limit($validated['text'], 2000);
        }

        return $message;
    }
}
