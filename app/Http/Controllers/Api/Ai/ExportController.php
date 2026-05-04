<?php

namespace App\Http\Controllers\Api\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ai\ExportIncidentsRequest;
use App\Http\Resources\Ai\IncidentExportResource;
use App\Models\Incident;
use App\Traits\ApiResponser;

/**
 * @group AI Export
 *
 * Bulk export endpoints for AI ingestion and data processing.
 * All endpoints require authentication via Bearer token and 'access api' permission.
 */
class ExportController extends Controller
{
    use ApiResponser;

    /**
     * Bulk export incidents
     *
     * Export multiple incidents in JSON format with pagination support.
     * Designed for AI ingestion with controlled payload size via limit/offset.
     *
     * @authenticated
     *
     * @queryParam limit integer Number of incidents to return per request. Default: 100. Maximum: 1000. Example: 100
     * @queryParam offset integer Number of incidents to skip before returning results. Default: 0. Example: 100
     * @queryParam start_date date Optional filter - only include incidents from this date (inclusive). Format: Y-m-d. Example: 2024-01-01
     * @queryParam end_date date Optional filter - only include incidents until this date (inclusive). Format: Y-m-d. Example: 2024-12-31
     * @queryParam severity string Optional filter - only include incidents with this severity. Allowed: P1, P2, P3, P4, G, X1, X2, X3, X4, Non Incident. Example: P1
     * @queryParam type string Optional filter - only include incidents of this type. Allowed: Tech, Non-tech. Example: Tech
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Incidents exported successfully.",
     *   "data": {
     *     "incidents": [
     *       {
     *         "id": 1,
     *         "no": "20250115_IN_1234",
     *         "title": "Payment Gateway Timeout",
     *         "summary": "5-minute outage during peak hours affecting payment processing",
     *         "root_cause": "Database connection pool exhausted due to high traffic",
     *         "timeline": "10:30 - Incident detected\n10:32 - Team notified\n10:35 - Stop bleeding achieved",
     *         "severity": "P1",
     *         "incident_type": "Tech",
     *         "incident_source": "Internal",
     *         "incident_status": "Completed",
     *         "incident_date": "2025-01-15T10:30:00",
     *         "discovered_at": "2025-01-15T10:32:00",
     *         "stop_bleeding_at": "2025-01-15T10:35:00",
     *         "entry_date_tech_risk": "2025-01-15",
     *         "fund_status": "Confirmed loss",
     *         "potential_fund_loss": 15000000,
     *         "recovered_fund": 5000000,
     *         "fund_loss": 5000000,
     *         "reported_by": "john.doe@company.com",
     *         "mttr": 5,
     *         "mtbf": 30,
     *         "pic": {
     *           "name": "John Doe",
     *           "email": "john.doe@company.com"
     *         },
     *         "labels": ["payment", "database", "timeout"],
     *         "created_at": "2025-01-15T11:00:00"
     *       }
     *     ],
     *     "pagination": {
     *       "limit": 100,
     *       "offset": 0,
     *       "total": 542,
     *       "has_more": true
     *     }
     *   }
     * }
     * @response 422 {
     *   "code": 422,
     *   "status": "Error",
     *   "message": "The limit field must be an integer. (and 1 more error)",
     *   "data": {
     *     "limit": ["The limit field must be an integer."],
     *     "severity": ["The selected severity is invalid."]
     *   }
     * }
     */
    public function export(ExportIncidentsRequest $request)
    {
        $validated = $request->validated();

        $limit = $validated['limit'] ?? 100;
        $offset = $validated['offset'] ?? 0;

        $query = Incident::with(['pic', 'labels']);

        // Apply date range filter
        if (isset($validated['start_date']) && isset($validated['end_date'])) {
            $query->whereBetween('incident_date', [$validated['start_date'], $validated['end_date']]);
        } elseif (isset($validated['start_date'])) {
            $query->whereDate('incident_date', '>=', $validated['start_date']);
        } elseif (isset($validated['end_date'])) {
            $query->whereDate('incident_date', '<=', $validated['end_date']);
        }

        // Apply severity filter
        if (isset($validated['severity'])) {
            $query->where('severity', $validated['severity']);
        }

        // Apply incident type filter
        if (isset($validated['type'])) {
            $query->where('incident_type', $validated['type']);
        }

        $total = $query->count();
        $incidents = $query
            ->offset($offset)
            ->limit($limit)
            ->orderBy('incident_date', 'desc')
            ->get();

        return $this->successResponse([
            'incidents' => IncidentExportResource::collection($incidents),
            'pagination' => [
                'limit' => (int) $limit,
                'offset' => (int) $offset,
                'total' => $total,
                'has_more' => ($offset + $limit) < $total,
            ],
        ], 'Incidents exported successfully.');
    }
}
