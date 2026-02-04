<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncidentApiResource;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Label;
use App\Traits\ApiResponser;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class IncidentController extends Controller
{
    use ApiResponser;

    public function index(Request $request)
    {
        try {
            // Build the query - don't cache query builders
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

            return $this->successResponse(
                IncidentApiResource::collection($query->paginate(15)),
                'Incidents retrieved successfully.'
            );
        } catch (Exception $e) {
            \Log::error('Failed to retrieve incidents: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_params' => $request->all(),
            ]);

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
            \Log::error('Failed to retrieve labels: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

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
            \Log::error('Failed to retrieve incident types: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

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
            \Log::error('Failed to create incident: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

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
                    'actionImprovements',
                ])),
                'Incident retrieved successfully.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            \Log::error('Failed to retrieve incident: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'incident_id' => $incident->id ?? 'unknown',
            ]);

            return $this->errorResponse('Failed to retrieve incident.', 500);
        }
    }

    public function showByNo(string $no)
    {
        try {
            $incident = Incident::where('no', $no)->firstOrFail();

            return $this->successResponse(
                new IncidentApiResource($incident->load([
                    'pic',
                    'statusUpdates',
                    'investigationDocuments',
                    'labels',
                    'actionImprovements',
                ])),
                'Incident retrieved successfully.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            \Log::error('Failed to retrieve incident: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'incident_no' => $no,
            ]);

            return $this->errorResponse('Failed to retrieve incident.', 500);
        }
    }

    public function showMarkdown(string $no)
    {
        try {
            $incident = Incident::where('no', $no)
                ->with([
                    'pic',
                    'statusUpdates',
                    'investigationDocuments',
                    'labels',
                    'actionImprovements',
                ])
                ->firstOrFail();

            $markdown = $this->convertToMarkdown($incident);

            return response($markdown, 200)
                ->header('Content-Type', 'text/markdown');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            \Log::error('Failed to retrieve incident markdown: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'incident_no' => $no,
            ]);

            return $this->errorResponse('Failed to retrieve incident.', 500);
        }
    }

    private function convertToMarkdown(Incident $incident): string
    {
        $md = [];

        // Header
        $md[] = "# {$incident->title}";
        $md[] = "";
        $md[] = "**Incident ID:** {$incident->no}";
        $md[] = "";

        // Basic Info
        $md[] = "## Basic Information";
        $md[] = "";
        $md[] = "| Field | Value |";
        $md[] = "|-------|-------|";
        $md[] = "| **Severity** | {$incident->severity} |";
        $md[] = "| **Type** | {$incident->incident_type} |";
        $md[] = "| **Source** | {$incident->incident_source} |";
        $md[] = "| **Incident Date** | {$incident->incident_date->format('Y-m-d')} |";
        $md[] = "| **Discovered At** | " . ($incident->discovered_at?->format('Y-m-d H:i') ?? 'N/A') . " |";
        $md[] = "| **Stop Bleeding At** | " . ($incident->stop_bleeding_at?->format('Y-m-d H:i') ?? 'N/A') . " |";
        $md[] = "| **Entry Date** | {$incident->entry_date_tech_risk->format('Y-m-d')} |";
        $md[] = "";

        // Summary
        $md[] = "## Summary";
        $md[] = "";
        $md[] = $incident->summary ?? 'No summary provided.';
        $md[] = "";

        // Root Cause
        if ($incident->root_cause) {
            $md[] = "## Root Cause";
            $md[] = "";
            $md[] = $incident->root_cause;
            $md[] = "";
        }

        // Financial Impact
        $md[] = "## Financial Impact";
        $md[] = "";
        $md[] = "- **Potential Fund Loss:** " . ($incident->potential_fund_loss ? number_format($incident->potential_fund_loss) : 'N/A');
        $md[] = "- **Actual Fund Loss:** " . ($incident->fund_loss ? number_format($incident->fund_loss) : 'N/A');
        $md[] = "";

        // PIC
        if ($incident->pic) {
            $md[] = "## Person In Charge";
            $md[] = "";
            $md[] = "- **Name:** {$incident->pic->name}";
            $md[] = "- **Email:** {$incident->pic->email}";
            $md[] = "";
        }

        // Third Party
        if ($incident->third_party_client) {
            $md[] = "## Third Party";
            $md[] = "";
            $md[] = $incident->third_party_client;
            $md[] = "";
        }

        // Labels/Tags
        if ($incident->labels && $incident->labels->isNotEmpty()) {
            $md[] = "## Labels";
            $md[] = "";
            foreach ($incident->labels as $label) {
                $md[] = "- `{$label->name}`";
            }
            $md[] = "";
        }

        // Status Updates
        if ($incident->statusUpdates && $incident->statusUpdates->isNotEmpty()) {
            $md[] = "## Status Updates";
            $md[] = "";
            foreach ($incident->statusUpdates as $update) {
                $md[] = "### {$update->updated_at->format('Y-m-d H:i')} - {$update->status}";
                $md[] = "";
                $md[] = $update->notes ?? 'No notes.';
                $md[] = "";
            }
        }

        // Action Improvements
        if ($incident->actionImprovements && $incident->actionImprovements->isNotEmpty()) {
            $md[] = "## Action Improvements";
            $md[] = "";
            foreach ($incident->actionImprovements as $action) {
                $statusIcon = $action->status === 'done' ? '✅' : '🔄';
                $md[] = "### {$statusIcon} {$action->title}";
                $md[] = "";
                $md[] = $action->detail;
                $md[] = "";
                // Safe date handling
                $dueDate = $action->due_date;
                if (is_string($dueDate)) {
                    $md[] = "- **Due Date:** {$dueDate}";
                } elseif ($dueDate && method_exists($dueDate, 'format')) {
                    $md[] = "- **Due Date:** {$dueDate->format('Y-m-d')}";
                } else {
                    $md[] = "- **Due Date:** N/A";
                }
                $md[] = "- **Status:** {$action->status}";
                if ($action->pic_email) {
                    $md[] = "- **PIC:** " . implode(', ', $action->pic_email);
                }
                $md[] = "";
            }
        }

        // Investigation Documents
        if ($incident->investigationDocuments && $incident->investigationDocuments->isNotEmpty()) {
            $md[] = "## Investigation Documents";
            $md[] = "";
            foreach ($incident->investigationDocuments as $doc) {
                $md[] = "### {$doc->title}";
                $md[] = "";
                $md[] = $doc->content ?? 'No content.';
                $md[] = "";
            }
        }

        // Metadata
        $md[] = "---";
        $md[] = "";
        $md[] = "*Reported by: " . ($incident->reported_by ?? 'N/A') . "*";

        return implode("\n", $md);
    }

    public function update(Request $request, Incident $incident)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'string|max:255',
                'summary' => 'string',
                'no' => 'string|max:255|unique:incidents,no,'.$incident->id,
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
            \Log::error('Failed to update incident: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'incident_id' => $incident->id ?? 'unknown',
                'request_data' => $request->all(),
            ]);

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
            \Log::error('Failed to delete incident: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'incident_id' => $incident->id ?? 'unknown',
            ]);

            return $this->errorResponse('Failed to delete incident.', 500);
        }
    }
}
