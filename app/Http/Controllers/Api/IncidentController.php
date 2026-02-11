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

/**
 * @group Incidents
 *
 * APIs for managing and retrieving technical incidents and issues.
 * All endpoints require authentication via Bearer token.
 */
class IncidentController extends Controller
{
    use ApiResponser;

    /**
     * List incidents
     *
     * Retrieve a paginated list of incidents with optional filtering.
     * Results are ordered by incident date (newest first) and include associated labels.
     *
     * @authenticated
     *
     * @queryParam start_date date Filter incidents from this date (inclusive). Example: 2024-01-01
     * @queryParam end_date date Filter incidents until this date (inclusive). Example: 2024-12-31
     * @queryParam min_fund_loss number Filter incidents with fund loss greater than or equal to this value. Example: 1000000
     * @queryParam max_fund_loss number Filter incidents with fund loss less than or equal to this value. Example: 50000000
     * @queryParam min_potential_fund_loss number Filter incidents with potential loss greater than or equal to this value. Example: 1000000
     * @queryParam max_potential_fund_loss number Filter incidents with potential loss less than or equal to this value. Example: 100000000
     * @queryParam tags string Filter by comma-separated label names. Example: payment,database,timeout
     * @queryParam type string Filter by incident type. Must be "Tech" or "Non-tech". Example: Tech
     * @queryParam page integer Page number for pagination. Default: 1. Example: 1
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Incidents retrieved successfully.",
     *   "data": {
     *     "data": [
     *       {
     *         "id": 1,
     *         "no": "20250115_IN_1234",
     *         "title": "Payment Gateway Timeout",
     *         "summary": "5-minute outage during peak hours...",
     *         "severity": "P1",
     *         "incident_type": "Tech",
     *         "incident_date": "2025-01-15T10:30:00.000000Z",
     *         "fund_loss": 5000000,
     *         "labels": ["payment", "database"]
     *       }
     *     ],
     *     "current_page": 1,
     *     "per_page": 15,
     *     "total": 42
     *   }
     * }
     *
     * @response 500 {
     *   "code": 500,
     *   "status": "Error",
     *   "message": "Failed to retrieve incidents.",
     *   "data": null
     * }
     */
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

    /**
     * Get all labels
     *
     * Retrieve a list of all available labels/tags used for categorizing incidents.
     * Results are cached for 60 minutes.
     *
     * @authenticated
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Labels retrieved successfully.",
     *   "data": ["payment", "database", "timeout", "network", "server", "api"]
     * }
     */
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

    /**
     * Get all incident types
     *
     * Retrieve a list of all available incident types.
     * Results are cached for 60 minutes.
     *
     * @authenticated
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Incident types retrieved successfully.",
     *   "data": ["Network Issue", "Server Error", "Database Timeout", "API Failure"]
     * }
     */
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

    /**
     * Get incident by ID
     *
     * Retrieve detailed information about a specific incident by its database ID.
     * Includes all related data: PIC, status updates, investigation documents, labels, and action improvements.
     *
     * @authenticated
     *
     * @urlParam id integer required The ID of the incident. Example: 1
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Incident retrieved successfully.",
     *   "data": {
     *     "id": 1,
     *     "no": "20250115_IN_1234",
     *     "title": "Payment Gateway Timeout",
     *     "summary": "5-minute outage during peak hours...",
     *     "root_cause": "Database connection pool exhausted due to high traffic",
     *     "severity": "P1",
     *     "incident_type": "Tech",
     *     "incident_source": "Internal",
     *     "incident_date": "2025-01-15T10:30:00.000000Z",
     *     "fund_loss": 5000000,
     *     "potential_fund_loss": 15000000,
     *     "pic": {
     *       "id": 5,
     *       "name": "John Doe",
     *       "email": "john.doe@company.com"
     *     },
     *     "labels": [
     *       {"id": 1, "name": "payment"},
     *       {"id": 2, "name": "database"}
     *     ],
     *     "status_updates": [
     *       {
     *         "id": 1,
     *         "status": "In progress",
     *         "notes": "Investigating database connection pool settings",
     *         "updated_at": "2025-01-15T11:00:00.000000Z"
     *       }
     *     ],
     *     "action_improvements": [
     *       {
     *         "id": 1,
     *         "title": "Increase connection pool size",
     *         "detail": "Configure pool to handle 2x peak traffic",
     *         "status": "pending",
     *         "due_date": "2025-01-20"
     *       }
     *     ]
     *   }
     * }
     *
     * @response 404 {
     *   "code": 404,
     *   "status": "Error",
     *   "message": "Incident not found.",
     *   "data": null
     * }
     */
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

    /**
     * Get incident by incident number
     *
     * Retrieve detailed information about a specific incident by its incident number (e.g., "20250115_IN_1234").
     * Includes all related data: PIC, status updates, investigation documents, labels, and action improvements.
     *
     * @authenticated
     *
     * @urlParam no string required The incident number (format: YYYYMD_IN_XXXX or YYYYMD_IS_XXXX). Example: 20250115_IN_1234
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Incident retrieved successfully.",
     *   "data": {
     *     "id": 1,
     *     "no": "20250115_IN_1234",
     *     "title": "Payment Gateway Timeout",
     *     "summary": "5-minute outage during peak hours...",
     *     "root_cause": "Database connection pool exhausted due to high traffic",
     *     "severity": "P1",
     *     "incident_type": "Tech",
     *     "incident_date": "2025-01-15T10:30:00.000000Z",
     *     "fund_loss": 5000000
     *   }
     * }
     *
     * @response 404 {
     *   "code": 404,
     *   "status": "Error",
     *   "message": "Incident not found.",
     *   "data": null
     * }
     */
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

    /**
     * Export incident as Markdown
     *
     * Retrieve an incident formatted as Markdown document.
     * Includes all related data formatted in a readable Markdown structure.
     * Useful for documentation, reporting, and AI ingestion.
     *
     * @authenticated
     *
     * @urlParam no string required The incident number. Example: 20250115_IN_1234
     *
     * @response {
     *   "# Payment Gateway Timeout",
     *   "",
     *   "**Incident ID:** 20250115_IN_1234",
     *   "",
     *   "## Basic Information",
     *   "",
     *   "| Field | Value |",
     *   "|-------|-------|",
     *   "| **Severity** | P1 |",
     *   "| **Type** | Tech |",
     *   "| **Source** | Internal |",
     *   "",
     *   "## Summary",
     *   "",
     *   "5-minute outage during peak hours...",
     *   "",
     *   "## Root Cause",
     *   "",
     *   "Database connection pool exhausted due to high traffic",
     *   ""
     * }
     *
     * @response 404 {
     *   "code": 404,
     *   "status": "Error",
     *   "message": "Incident not found.",
     *   "data": null
     * }
     */
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
