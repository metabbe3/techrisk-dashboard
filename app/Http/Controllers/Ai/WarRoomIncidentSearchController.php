<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarRoomIncidentSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->input('q', '');

        if (strlen($q) < 2) {
            return $this->successResponse(['incidents' => []]);
        }

        $incidents = Incident::where('no', 'LIKE', "%{$q}%")
            ->orWhere('title', 'LIKE', "%{$q}%")
            ->orWhere('summary', 'LIKE', "%{$q}%")
            ->with('pic')
            ->orderBy('incident_date', 'desc')
            ->limit(10)
            ->get()
            ->map(fn (Incident $inc) => [
                'id' => $inc->id,
                'no' => $inc->no,
                'title' => $inc->title ?? 'Untitled',
                'severity' => $inc->severity,
                'status' => $inc->incident_status,
                'date' => $inc->incident_date?->format('Y-m-d'),
                'pic' => $inc->pic?->name,
                'classification' => $inc->classification,
            ]);

        return $this->successResponse(['incidents' => $incidents]);
    }
}
