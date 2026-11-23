<?php

declare(strict_types=1);

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

        // W3 fix: the ONE scope — the war-room search must not expose
        // records outside the caller's year/team slice. The search terms
        // stay BRACKETED: a bare orWhere after the scope would OR against
        // the scope itself and reopen the hole.
        $incidents = Incident::query()
            ->applyUserYearAccess($request->user())
            ->where(function ($w) use ($q) {
                $w->where('no', 'LIKE', "%{$q}%")
                    ->orWhere('title', 'LIKE', "%{$q}%")
                    ->orWhere('summary', 'LIKE', "%{$q}%");
            })
            ->with('pics')
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
                'pic' => $inc->pic_names !== '' ? $inc->pic_names : null,
                'classification' => $inc->classification,
            ]);

        return $this->successResponse(['incidents' => $incidents]);
    }
}
