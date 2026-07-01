<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarkAiEnhancedController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'incident_id' => 'required|integer|exists:incidents,id',
            'fields' => 'required|array|min:1',
            'fields.*' => 'required|string',
        ]);

        $incident = Incident::find($validated['incident_id']);

        if (! $incident) {
            return $this->errorResponse('Incident not found.', 404);
        }

        $incident->markAiEnhancedFields($validated['fields']);

        return $this->successResponse([
            'success' => true,
            'marked_fields' => array_keys($validated['fields']),
        ]);
    }
}
