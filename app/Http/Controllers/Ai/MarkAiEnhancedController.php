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
            return response()->json(['success' => false, 'error' => 'Incident not found.'], 404);
        }

        $enhanced = $incident->ai_enhanced_fields ?? [];

        foreach ($validated['fields'] as $field => $text) {
            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $enhanced[$field] = [
                'enhanced' => true,
                'hash' => md5(trim($text)),
            ];
        }

        $incident->update(['ai_enhanced_fields' => $enhanced]);

        return response()->json([
            'success' => true,
            'marked_fields' => array_keys($validated['fields']),
        ]);
    }
}
