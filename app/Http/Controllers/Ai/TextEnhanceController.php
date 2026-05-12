<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Ai\AiTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TextEnhanceController extends Controller
{
    public function __construct(
        private readonly AiTextService $aiService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|max:5000',
            'field_type' => 'required|string|in:summary,root_cause,timeline,remark',
            'model' => 'nullable|string',
            'additional_prompt' => 'nullable|string|max:1000',
            'incident_id' => 'nullable|integer|exists:incidents,id',
        ]);

        $result = $this->aiService->enhance(
            text: $validated['text'],
            fieldType: $validated['field_type'],
            model: $validated['model'] ?? null,
            additionalPrompt: $validated['additional_prompt'] ?? null,
        );

        if ($result->success && ! empty($validated['incident_id'])) {
            $this->markFieldAiEnhanced($validated['incident_id'], $validated['field_type'], $result->text);
        }

        return response()->json([
            'success' => $result->success,
            'text' => $result->text,
            'error' => $result->error,
            'model' => $result->model,
        ]);
    }

    private function markFieldAiEnhanced(int $incidentId, string $fieldType, string $aiText): void
    {
        $incident = Incident::find($incidentId);

        if (! $incident) {
            return;
        }

        $fields = $incident->ai_enhanced_fields ?? [];
        $fields[$fieldType] = [
            'enhanced' => true,
            'hash' => md5(trim($aiText)),
        ];
        $incident->update(['ai_enhanced_fields' => $fields]);
    }
}
