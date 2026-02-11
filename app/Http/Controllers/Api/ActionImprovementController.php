<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActionImprovementResource;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Traits\ApiResponser;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
     *       "due_date": "2025-01-20",
     *       "pic_email": ["john.doe@company.com", "jane.smith@company.com"]
     *     }
     *   ]
     * }
     */
    public function index(Incident $incident)
    {
        try {
            return $this->successResponse(
                ActionImprovementResource::collection($incident->actionImprovements),
                'Action improvements retrieved successfully.'
            );
        } catch (Exception $e) {
            return $this->errorResponse('Failed to retrieve action improvements.', 500);
        }
    }

    /**
     * Create action improvement
     *
     * Create a new action improvement for a specific incident.
     *
     * @authenticated
     *
     * @urlParam incident integer required The ID of the incident. Example: 1
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
    public function store(Request $request, Incident $incident)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'detail' => 'required|string',
                'due_date' => 'required|date',
                'pic_email' => 'required|array',
                'reminder' => 'boolean',
                'reminder_frequency' => 'nullable|string',
                'status' => 'string|in:pending,done',
            ]);

            $actionImprovement = $incident->actionImprovements()->create($validated);

            return $this->successResponse(
                new ActionImprovementResource($actionImprovement),
                'Action improvement created successfully.',
                201
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create action improvement.', 500);
        }
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
     *     "due_date": "2025-01-20",
     *     "pic_email": ["john.doe@company.com"]
     *   }
     * }
     *
     * @response 404 {
     *   "code": 404,
     *   "status": "Error",
     *   "message": "Action improvement not found.",
     *   "data": null
     * }
     */
    public function show(ActionImprovement $actionImprovement)
    {
        try {
            return $this->successResponse(
                new ActionImprovementResource($actionImprovement),
                'Action improvement retrieved successfully.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Action improvement not found.', 404);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to retrieve action improvement.', 500);
        }
    }

    /**
     * Update action improvement
     *
     * Update an existing action improvement.
     *
     * @authenticated
     *
     * @urlParam action_improvement integer required The ID of the action improvement. Example: 1
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
    public function update(Request $request, ActionImprovement $actionImprovement)
    {
        try {
            $validated = $request->validate([
                'title' => 'string|max:255',
                'detail' => 'string',
                'due_date' => 'date',
                'pic_email' => 'array',
                'reminder' => 'boolean',
                'reminder_frequency' => 'nullable|string',
                'status' => 'string|in:pending,done',
            ]);

            $actionImprovement->update($validated);

            return $this->successResponse(
                new ActionImprovementResource($actionImprovement),
                'Action improvement updated successfully.'
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Action improvement not found.', 404);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update action improvement.', 500);
        }
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
     *
     * @response 404 {
     *   "code": 404,
     *   "status": "Error",
     *   "message": "Action improvement not found.",
     *   "data": null
     * }
     */
    public function destroy(ActionImprovement $actionImprovement)
    {
        try {
            $actionImprovement->delete();

            return $this->successResponse(null, 'Action improvement deleted successfully.', 204);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Action improvement not found.', 404);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete action improvement.', 500);
        }
    }
}
