<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiSearchController extends Controller
{
    public function __construct(
        private readonly AiTextService $aiService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|min:3|max:500',
            'model' => 'nullable|string',
        ]);

        $result = $this->aiService->parseNaturalLanguageQuery(
            query: $validated['query'],
            model: $validated['model'] ?? null,
        );

        return $this->successResponse([
            'success' => true,
            'filters' => $result['filters'],
            'explanation' => $result['explanation'],
        ]);
    }
}
