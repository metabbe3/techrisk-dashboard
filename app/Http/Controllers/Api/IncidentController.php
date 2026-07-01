<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListIncidentsRequest;
use App\Http\Requests\Api\V1\StoreIncidentRequest;
use App\Http\Requests\Api\V1\UpdateIncidentRequest;
use App\Http\Resources\CategoryApiResource;
use App\Http\Resources\IncidentApiResource;
use App\Http\Resources\IncidentListApiResource;
use App\Models\Category;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Label;
use App\Models\User;
use App\Services\IncidentFormatter;
use App\Traits\ApiResponser;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
     * @queryParam limit integer Number of records to return (1–500). Omit to return ALL matching records. Example: 20
     * @queryParam offset integer Records to skip (default 0). Use with `limit` for pagination. Example: 40
     * @queryParam per_page integer Alias for `limit` (backward-compatible). Example: 20
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Incidents retrieved successfully.",
     *   "data": [
     *     {
     *       "id": 1,
     *       "no": "20250115_IN_1234",
     *       "title": "Payment Gateway Timeout",
     *       "summary": "5-minute outage during peak hours...",
     *       "severity": "P1",
     *       "incident_type": "Tech",
     *       "incident_date": "2025-01-15T10:30:00.000000Z",
     *       "fund_loss": 5000000,
     *       "labels": ["payment", "database"]
     *     }
     *   ],
     *   "meta": {
     *     "total": 42,
     *     "limit": 20,
     *     "offset": 0,
     *     "returned": 20,
     *     "has_more": true
     *   }
     * }
     * @response 500 {
     *   "code": 500,
     *   "status": "Error",
     *   "message": "Failed to retrieve incidents.",
     *   "data": null
     * }
     */
    public function index(ListIncidentsRequest $request)
    {
        try {
            $query = Incident::query();

            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('incident_date', [$request->validated('start_date'), $request->validated('end_date')]);
            }

            if ($request->filled('min_fund_loss')) {
                $query->where('fund_loss', '>=', $request->validated('min_fund_loss'));
            }

            if ($request->filled('max_fund_loss')) {
                $query->where('fund_loss', '<=', $request->validated('max_fund_loss'));
            }

            if ($request->filled('min_potential_fund_loss')) {
                $query->where('potential_fund_loss', '>=', $request->validated('min_potential_fund_loss'));
            }

            if ($request->filled('max_potential_fund_loss')) {
                $query->where('potential_fund_loss', '<=', $request->validated('max_potential_fund_loss'));
            }

            if ($request->filled('tags')) {
                $tags = explode(',', $request->validated('tags'));
                $query->whereHas('labels', function ($q) use ($tags) {
                    $q->whereIn('name', $tags);
                });
            }

            if ($request->filled('type')) {
                $query->where('incident_type', $request->validated('type'));
            }

            if ($request->filled('severity')) {
                $query->where('severity', $request->validated('severity'));
            }

            if ($request->filled('incident_status')) {
                $query->where('incident_status', $request->validated('incident_status'));
            }

            if ($request->filled('classification')) {
                $query->where('classification', $request->validated('classification'));
            }

            if ($request->filled('fund_status')) {
                $query->where('fund_status', $request->validated('fund_status'));
            }

            if ($request->filled('pic_id')) {
                $query->where('pic_id', $request->validated('pic_id'));
            }

            if ($request->filled('search')) {
                $search = $request->validated('search');
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('summary', 'like', "%{$search}%")
                        ->orWhere('no', 'like', "%{$search}%");
                });
            }

            // Pagination: limit/offset — omit both to return ALL matching records.
            // `per_page` is a backward-compatible alias for `limit`.
            $limit = $request->validated('limit') ?? $request->validated('per_page');
            $limit = $limit !== null ? (int) $limit : null;
            $offset = (int) ($request->validated('offset', 0) ?? 0);

            $total = $query->count();
            $items = $limit
                ? $query->skip($offset)->take($limit)->get()
                : $query->get();

            return $this->successResponse(
                IncidentListApiResource::collection($items),
                'Incidents retrieved successfully.',
                meta: [
                    'total' => $total,
                    'limit' => $limit ?? $total,
                    'offset' => $offset,
                    'returned' => $items->count(),
                    'has_more' => ($offset + $items->count()) < $total,
                ],
            );
        } catch (Exception $e) {
            Log::error('Failed to retrieve incidents: '.$e->getMessage(), [
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
                return Label::pluck('name');
            });

            return $this->successResponse($labels, 'Labels retrieved successfully.');
        } catch (Exception $e) {
            Log::error('Failed to retrieve labels: '.$e->getMessage(), [
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
                return IncidentType::pluck('name');
            });

            return $this->successResponse($incidentTypes, 'Incident types retrieved successfully.');
        } catch (Exception $e) {
            Log::error('Failed to retrieve incident types: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to retrieve incident types.', 500);
        }
    }

    /**
     * Get all users (PIC lookup)
     *
     * Retrieve a list of users with their IDs and names.
     * Useful for resolving PIC IDs from incident data.
     *
     * @authenticated
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Users retrieved successfully.",
     *   "data": [{"id": 1, "name": "John Doe"}, {"id": 2, "name": "Jane Smith"}]
     * }
     */
    public function getUsers()
    {
        try {
            $users = User::select('id', 'name')->orderBy('name')->get();

            return $this->successResponse($users, 'Users retrieved successfully.');
        } catch (Exception $e) {
            Log::error('Failed to retrieve users: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to retrieve users.', 500);
        }
    }

    /**
     * Get all categories
     *
     * Retrieve all available categories grouped by type (business_category, root_cause_category, responsible_team).
     * Results are cached for 60 minutes.
     *
     * @authenticated
     *
     * @response {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Categories retrieved successfully.",
     *   "data": [
     *     {"id": 1, "type": "business_category", "name": "Operations"},
     *     {"id": 2, "type": "business_category", "name": "Finance"},
     *     {"id": 3, "type": "root_cause_category", "name": "Human Error"}
     *   ]
     * }
     */
    public function getCategories()
    {
        try {
            $categories = Category::orderBy('type')->orderBy('name')->get();

            return $this->successResponse(
                CategoryApiResource::collection($categories),
                'Categories retrieved successfully.'
            );
        } catch (Exception $e) {
            Log::error('Failed to retrieve categories: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to retrieve categories.', 500);
        }
    }

    public function store(StoreIncidentRequest $request)
    {
        try {
            $validatedData = $request->validated();

            $incident = Incident::create($validatedData);

            return $this->successResponse(
                new IncidentApiResource($incident),
                'Incident created successfully.',
                201
            );
        } catch (Exception $e) {
            Log::error('Failed to create incident: '.$e->getMessage(), [
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
     *     "incident_no": "20250115_IN_1234",
     *     "incident_name": "Payment Gateway Timeout",
     *     "summary": "5-minute outage during peak hours...",
     *     "root_cause": "Database connection pool exhausted due to high traffic",
     *     "severity": "P1",
     *     "incident_type": "Tech",
     *     "incident_source": "Internal",
     *     "incident_date": "2025-01-15T10:30:00.000000Z",
     *     "fund_loss": 5000000,
     *     "potential_fund_loss": 15000000,
     *     "pic": {"id": 5, "name": "John Doe"},
     *     "labels": [{"id": 1, "name": "payment"}],
     *     "status_updates": [{"id": 1, "status": "In progress", "update_date": "2025-01-15"}],
     *     "action_improvements": [{"id": 1, "title": "Increase pool size", "status": "pending"}]
     *   }
     * }
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
                new IncidentApiResource($incident->load(Incident::FULL_RELATIONS)),
                'Incident retrieved successfully.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            Log::error('Failed to retrieve incident: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'incident_id' => $incident->id,
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
                new IncidentApiResource($incident->load(Incident::FULL_RELATIONS)),
                'Incident retrieved successfully.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            Log::error('Failed to retrieve incident: '.$e->getMessage(), [
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
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Incident markdown retrieved successfully. Decode data with base64 to get markdown content.",
     *   "data": "IyBQYXltZW50IEdhdGV3YXkgVGltZW91dAoqKkluY2lkZW50IElEOioqIDIwMjUwMTE1X0lOXzEyMzQ="
     * }
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
                ->with(Incident::FULL_RELATIONS)
                ->firstOrFail();

            $markdown = IncidentFormatter::toMarkdown($incident);

            return $this->successResponse(
                base64_encode($markdown),
                'Incident markdown retrieved successfully. Decode data with base64 to get markdown content.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            Log::error('Failed to retrieve incident markdown: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'incident_no' => $no,
            ]);

            return $this->errorResponse('Failed to retrieve incident.', 500);
        }
    }

    public function update(UpdateIncidentRequest $request, Incident $incident)
    {
        try {
            $validatedData = $request->validated();

            $incident->update($validatedData);

            return $this->successResponse(
                new IncidentApiResource($incident),
                'Incident updated successfully.'
            );
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            Log::error('Failed to update incident: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'incident_id' => $incident->id,
                'request_data' => $request->all(),
            ]);

            return $this->errorResponse('Failed to update incident.', 500);
        }
    }

    public function destroy(Incident $incident)
    {
        try {
            $incident->delete();

            return response()->noContent();
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Incident not found.', 404);
        } catch (Exception $e) {
            Log::error('Failed to delete incident: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'incident_id' => $incident->id,
            ]);

            return $this->errorResponse('Failed to delete incident.', 500);
        }
    }
}
