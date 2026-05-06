<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
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
        ]);

        $result = $this->aiService->enhance(
            text: $validated['text'],
            fieldType: $validated['field_type'],
            model: $validated['model'] ?? null,
        );

        return response()->json([
            'success' => $result->success,
            'text' => $result->text,
            'error' => $result->error,
            'model' => $result->model,
        ]);
    }
}
