<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreActionImprovementRequest;
use App\Http\Requests\Api\V1\UpdateActionImprovementRequest;
use App\Http\Resources\ActionImprovementResource;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;

/**
 * @group Action Improvements
 *
 * APIs for managing action improvements associated with incidents.
 * Action improvements are corrective or preventive actions taken in response to incidents.
 * All endpoints require authentication via Bearer token.
 */
class ActionImprovementController extends Controller
{
    use ApiResponser;

    /**
     * List action improvements for an incident
     *
     * Retrieve all action improvements associated with a specific incident.
     *
     * @authenticated
     *
     * @urlParam incident integer required The ID of the incident. Example: 1
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Action improvements retrieved successfully.",
     *   "data": [
     *     {
     *       "id": 1,
     *       "title": "Increase connection pool size",
     *       "detail": "Configure pool to handle 2x peak traffic",
     *       "status": "pending",
     *       "due_date": "2025-01-20"
     *     }
     *   ]
     * }
     */
    public function index(Incident $incident)
    {
        return $this->successResponse(
            ActionImprovementResource::collection($incident->actionImprovements),
            'Action improvements retrieved successfully.'
        );
    }

    /**
     * Create action improvement
     *
     * Create a new action improvement for a specific incident.
     *
     * @authenticated
     *
     * @urlParam incident integer required The ID of the incident. Example: 1
     *
     * @bodyParam title string required The title of the action. Example: Increase connection pool size
     * @bodyParam detail string required Detailed description of the action. Example: Configure pool to handle 2x peak traffic
     * @bodyParam due_date date required The due date for the action. Example: 2025-01-20
     * @bodyParam pic_email array required Array of PIC email addresses. Example: ["john.doe@company.com", "jane.smith@company.com"]
     * @bodyParam reminder boolean Enable reminders for this action. Example: true
     * @bodyParam reminder_frequency string Reminder frequency (e.g., "daily", "weekly"). Example: weekly
     * @bodyParam status string Status of the action. Must be "pending" or "done". Example: pending
     *
     * @response {
     *   "code": 201,
     *   "status": "Success",
     *   "message": "Action improvement created successfully.",
     *   "data": {
     *     "id": 1,
     *     "title": "Increase connection pool size",
     *     "detail": "Configure pool to handle 2x peak traffic",
     *     "status": "pending",
     *     "due_date": "2025-01-20"
     *   }
     * }
     */
    public function store(StoreActionImprovementRequest $request, Incident $incident)
    {
        $validated = $request->validated();

        $actionImprovement = $incident->actionImprovements()->create($validated);

        return $this->successResponse(
            new ActionImprovementResource($actionImprovement),
            'Action improvement created successfully.',
            201
        );
    }

    /**
     * Search action improvements across incidents (the DingTalk agent's view)
     *
     * "What's overdue this week / for X". Defaults to OPEN work
     * (pending + on_progress via ActionImprovement::openStatuses() — the ONE
     * map every gate shares); `status=` overrides it. `overdue=1` applies the
     * governance definition (open work, due before today) — the same query
     * GovernanceKpiService::overdueActionItemCount() runs, never re-derived.
     *
     * @authenticated
     *
     * @queryParam status string One of the real statuses (draft/pending/on_progress/done/cancelled/tbd). Example: pending
     * @queryParam overdue boolean 1 = open work past its due date (null due dates are TBD, never chased). Example: 1
     * @queryParam pic string Filter to actions where this email is a PIC. Example: kevin@dana.id
     * @queryParam incident_no string Scope to one incident number. Example: 20250115_IN_1234
     * @queryParam limit integer Rows to return, 1-100 (default 20). Use 1 for count-only via meta.total. Example: 20
     * @queryParam offset integer Rows to skip (paging). Example: 0
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', ActionImprovement::STATUSES)],
            'overdue' => ['nullable', 'boolean'],
            'pending_approval' => ['nullable', 'boolean'],
            'pic' => ['nullable', 'email'],
            'incident_no' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $query = ActionImprovement::query()
            ->with('incident:id,no,title')
            ->withCount([
                'evidence as pending_proof_count' => fn ($q) => $q
                    ->whereNotNull('submitted_by')
                    ->whereNull('approved_at'),
            ])
            ->whereHas('incident', fn ($q) => $q->applyUserYearAccess($request->user()));

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        } else {
            $query->whereIn('status', ActionImprovement::openStatuses());
        }

        if (! empty($data['overdue'])) {
            // The KPI's overdue definition (GovernanceKpiService::overdueActionItemCount):
            // open work with a due date before today. A null due date is TBD — never chased.
            $query->whereIn('status', ActionImprovement::openStatuses())
                ->whereDate('due_date', '<', today());
        }

        if (! empty($data['pending_approval'])) {
            $query->withPendingProof();
        }

        if (isset($data['pic'])) {
            $query->whereJsonContains('pic_email', $data['pic']);
        }

        if (isset($data['incident_no'])) {
            $query->whereHas('incident', fn ($q) => $q->where('no', $data['incident_no']));
        }

        $limit = (int) ($data['limit'] ?? 20);
        $offset = (int) ($data['offset'] ?? 0);

        $total = (clone $query)->count();
        $rows = $query->orderBy('due_date')->orderBy('id')
            ->skip($offset)->take($limit)->get();

        return $this->successResponse(
            ActionImprovementResource::collection($rows),
            'Action improvements retrieved successfully.',
            meta: [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'returned' => $rows->count(),
                'has_more' => ($offset + $rows->count()) < $total,
            ],
        );
    }

    /**
     * Get action improvement by ID
     *
     * Retrieve detailed information about a specific action improvement.
     *
     * @authenticated
     *
     * @urlParam action_improvement integer required The ID of the action improvement. Example: 1
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Action improvement retrieved successfully.",
     *   "data": {
     *     "id": 1,
     *     "title": "Increase connection pool size",
     *     "detail": "Configure pool to handle 2x peak traffic",
     *     "status": "pending",
     *     "due_date": "2025-01-20"
     *   }
     * }
     * @response 404 {
     *   "code": 404,
     *   "status": "Error",
     *   "message": "Action improvement not found.",
     *   "data": null
     * }
     */
    public function show(ActionImprovement $actionImprovement)
    {
        return $this->successResponse(
            new ActionImprovementResource($actionImprovement),
            'Action improvement retrieved successfully.'
        );
    }

    /**
     * Update action improvement
     *
     * Update an existing action improvement.
     *
     * @authenticated
     *
     * @urlParam action_improvement integer required The ID of the action improvement. Example: 1
     *
     * @bodyParam title string The title of the action. Example: Increase connection pool size
     * @bodyParam detail string Detailed description of the action. Example: Configure pool to handle 2x peak traffic
     * @bodyParam due_date date The due date for the action. Example: 2025-01-20
     * @bodyParam pic_email array Array of PIC email addresses. Example: ["john.doe@company.com"]
     * @bodyParam reminder boolean Enable reminders for this action. Example: true
     * @bodyParam reminder_frequency string Reminder frequency. Example: weekly
     * @bodyParam status string Status of the action. Must be "pending" or "done". Example: done
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Action improvement updated successfully.",
     *   "data": {
     *     "id": 1,
     *     "title": "Increase connection pool size",
     *     "status": "done"
     *   }
     * }
     */
    public function update(UpdateActionImprovementRequest $request, ActionImprovement $actionImprovement)
    {
        $validated = $request->validated();

        $actionImprovement->update($validated);

        return $this->successResponse(
            new ActionImprovementResource($actionImprovement),
            'Action improvement updated successfully.'
        );
    }

    /**
     * Delete action improvement
     *
     * Permanently delete an action improvement.
     *
     * @authenticated
     *
     * @urlParam action_improvement integer required The ID of the action improvement. Example: 1
     *
     * @response {
     *   "code": 204,
     *   "status": "Success",
     *   "message": "Action improvement deleted successfully.",
     *   "data": null
     * }
     * @response 404 {
     *   "code": 404,
     *   "status": "Error",
     *   "message": "Action improvement not found.",
     *   "data": null
     * }
     */
    public function destroy(ActionImprovement $actionImprovement)
    {
        $actionImprovement->delete();

        return response()->noContent();
    }
}
