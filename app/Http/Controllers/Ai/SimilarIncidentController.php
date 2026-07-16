<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\IncidentSimilarIncident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimilarIncidentController extends Controller
{
    /**
     * Active (non-dismissed) similar incidents for an incident — the persisted
     * list the admin reviews and curates.
     */
    public function index(Request $request, Incident $incident): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['similar' => $incident->activeSimilarCards()],
        ]);
    }

    /**
     * Dismiss (soft-delete) a similar incident so the admin can re-verify.
     * A re-run of Find Similar will re-surface it if still considered similar.
     */
    public function destroy(Request $request, Incident $incident, IncidentSimilarIncident $similar): JsonResponse
    {
        if ($similar->incident_id !== $incident->id) {
            abort(404);
        }

        $similar->update([
            'dismissed_at' => now(),
            'dismissed_by' => $request->user()?->id,
        ]);

        return response()->json(['success' => true]);
    }
}
