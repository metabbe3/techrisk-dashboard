<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActionImprovementResource;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class ActionImprovementController extends Controller
{
    use ApiResponser;

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
