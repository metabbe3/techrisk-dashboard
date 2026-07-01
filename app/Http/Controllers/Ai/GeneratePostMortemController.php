<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Services\Ai\PostMortemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeneratePostMortemController extends Controller
{
    public function __construct(
        private readonly PostMortemService $postMortemService
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'incident_id' => 'required|exists:incidents,id',
        ]);

        $incident = Incident::with(Incident::FULL_RELATIONS)
            ->with('incidentType')
            ->findOrFail($validated['incident_id']);

        $result = $this->postMortemService->generate($incident);

        return $this->successResponse([
            'success' => true,
            'data' => $result,
        ]);
    }
}
