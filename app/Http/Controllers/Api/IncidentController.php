<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentApiResource;
use App\Models\Incident;
use App\Models\Label;
use App\Models\IncidentType;
use App\Traits\ApiResponser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Exception;

class IncidentController extends Controller
{
    use ApiResponser;

    public function index(Request $request)
    {
        try {
            // Create a more efficient cache key that excludes pagination
            $cacheKey = 'incidents.' . md5(json_encode([
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'min_fund_loss' => $request->min_fund_loss,
                'max_fund_loss' => $request->max_fund_loss,
                'min_potential_fund_loss' => $request->min_potential_fund_loss,
                'max_potential_fund_loss' => $request->max_potential_fund_loss,
                'tags' => $request->tags,
                'type' => $request->type,
            ]));

            $query = Cache::tags(['incidents'])->remember($cacheKey, 60, function () use ($request) {
                $query = Incident::with(['labels']);

                if ($request->has('start_date') && $request->has('end_date')) {
                    $query->whereBetween('incident_date', [$request->start_date, $request->end_date]);
                }

                if ($request->has('min_fund_loss')) {
                    $query->where('fund_loss', '>=', $request->min_fund_loss);
                }

                if ($request->has('max_fund_loss')) {
                    $query->where('fund_loss', '<=', $request->max_fund_loss);
                }

                if ($request->has('min_potential_fund_loss')) {
                    $query->where('potential_fund_loss', '>=', $request->min_potential_fund_loss);
                }

                if ($request->has('max_potential_fund_loss')) {
                    $query->where('potential_fund_loss', '<=', $request->max_potential_fund_loss);
                }

                if ($request->filled('tags')) {
                    $tags = explode(',', $request->input('tags'));
                    $query->whereHas('labels', function ($q) use ($tags) {
                        $q->whereIn('name', $tags);
                    });
                }

                if ($request->filled('type')) {
                    $query->where('incident_type', $request->input('type'));
                }

                return $query;
            });

            return $this->successResponse(
                IncidentApiResource::collection($query->paginate(15)),
                'Incidents retrieved successfully.'
            );
        } catch (Exception $e) {
            return $this->errorResponse('Failed to retrieve incidents.', 500);
        }
    }

    public function getLabels()
    {
        try {
            $labels = Cache::remember('labels', 60, function () {
                return Label::all()->pluck('name');
            });
            return $this->successResponse($labels, 'Labels retrieved successfully.');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to retrieve labels.', 500);
        }
    }

    public function getIncidentTypes()
    {
        try {
            $incidentTypes = Cache::remember('incident_types', 60, function () {
                return IncidentType::all()->pluck('name');
            });
            return $this->successResponse($incidentTypes, 'Incident types retrieved successfully.');
        } catch (Exception $e) {
            return $this->errorResponse('Failed to retrieve incident types.', 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'required|string|max:255',
                'summary' => 'required|string',
                'no' => 'required|string|max:255|unique:incidents,no',
                'root_cause' => 'nullable|string',
                'severity' => 'required|string',
                'incident_type' => 'required|in:Tech,Non-tech',
                'incident_source' => 'required|in:Internal,External',
                'goc_upload' => 'boolean',
                'teams_upload' => 'boolean',
                'discovered_at' => 'nullable|date',
                'stop_bleeding_at' => 'nullable|date',
                'incident_date' => 'required|date',
                'entry_date_tech_risk' => 'required|date',
                'pic_id' => 'nullable|exists:users,id',
                'reported_by' => 'nullable|string',
                'involved_third_party' => 'nullable|string',
                'potential_fund_loss' => 'nullable|numeric',
                'fund_loss' => 'nullable|numeric',
                'people_caused' => 'nullable|array',
                'checker' => 'nullable|string',
                'maker' => 'nullable|string',
            ]);

            $incident = Incident::create($validatedData);

            return $this->successResponse(
                new IncidentApiResource($incident),
                'Incident created successfully.',
                201
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to create incident.', 500);
        }
    }

    public function show(Incident $incident)
    {
        try {
            return $this->successResponse(
                new IncidentApiResource($incident->load([
                    'pic',
                    'statusUpdates',
                    'investigationDocuments',
                    'labels',
                    'actionImprovements'
                ])),
                'Incident retrieved successfully.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to retrieve incident.', 500);
        }
    }

    public function update(Request $request, Incident $incident)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'string|max:255',
                'summary' => 'string',
                'no' => 'string|max:255|unique:incidents,no,' . $incident->id,
                'root_cause' => 'nullable|string',
                'severity' => 'string',
                'incident_type' => 'in:Tech,Non-tech',
                'incident_source' => 'in:Internal,External',
                'goc_upload' => 'boolean',
                'teams_upload' => 'boolean',
                'discovered_at' => 'nullable|date',
                'stop_bleeding_at' => 'nullable|date',
                'incident_date' => 'date',
                'entry_date_tech_risk' => 'date',
                'pic_id' => 'nullable|exists:users,id',
                'reported_by' => 'nullable|string',
                'involved_third_party' => 'nullable|string',
                'potential_fund_loss' => 'nullable|numeric',
                'fund_loss' => 'nullable|numeric',
                'people_caused' => 'nullable|array',
                'checker' => 'nullable|string',
                'maker' => 'nullable|string',
            ]);

            $incident->update($validatedData);

            return $this->successResponse(
                new IncidentApiResource($incident),
                'Incident updated successfully.'
            );
        } catch (ValidationException $e) {
            return $this->errorResponse($e->errors(), 422);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to update incident.', 500);
        }
    }

    public function destroy(Incident $incident)
    {
        try {
            $incident->delete();

            return $this->successResponse(null, 'Incident deleted successfully.', 204);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            return $this->errorResponse('Failed to delete incident.', 500);
        }
    }
}
