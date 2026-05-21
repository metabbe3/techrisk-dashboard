<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreActionImprovementRequest;
use App\Http\Requests\Api\V1\UpdateActionImprovementRequest;
use App\Http\Resources\ActionImprovementResource;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Traits\ApiResponser;

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

        return $this->successResponse(null, 'Action improvement deleted successfully.', 204);
    }
}
