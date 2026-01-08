<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActionImprovementResource;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;

class ActionImprovementController extends Controller
{
    use ApiResponser;

    public function index(Incident $incident)
    {
        return $this->successResponse(ActionImprovementResource::collection($incident->actionImprovements), 'Action improvements retrieved successfully.');
    }

    public function store(Request $request, Incident $incident)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'detail' => 'required|string',
            'due_date' => 'required|date',
            'pic_email' => 'required|array',
            'reminder' => 'boolean',
            'reminder_frequency' => 'nullable|string',
            'status' => 'string|in:pending,done',
        ]);

        $actionImprovement = $incident->actionImprovements()->create($request->all());

        return $this->successResponse(new ActionImprovementResource($actionImprovement), 'Action improvement created successfully.', 201);
    }

    public function show(ActionImprovement $actionImprovement)
    {
        return $this->successResponse(new ActionImprovementResource($actionImprovement), 'Action improvement retrieved successfully.');
    }

    public function update(Request $request, ActionImprovement $actionImprovement)
    {
        $request->validate([
            'title' => 'string|max:255',
            'detail' => 'string',
            'due_date' => 'date',
            'pic_email' => 'array',
            'reminder' => 'boolean',
            'reminder_frequency' => 'nullable|string',
            'status' => 'string|in:pending,done',
        ]);

        $actionImprovement->update($request->all());

        return $this->successResponse(new ActionImprovementResource($actionImprovement), 'Action improvement updated successfully.');
    }

    public function destroy(ActionImprovement $actionImprovement)
    {
        $actionImprovement->delete();

        return $this->successResponse(null, 'Action improvement deleted successfully.', 204);
    }
}
