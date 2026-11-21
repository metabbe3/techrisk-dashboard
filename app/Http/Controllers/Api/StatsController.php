<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\IncidentClassification;
use App\Filament\Statistics\IncidentStatsFooterData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StatsRequest;
use App\Models\Incident;
use App\Services\Analytics\AnalyticsQueryService;
use App\Traits\ApiResponser;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * @group Stats
 *
 * Aggregated dashboard metrics for the DingTalk agent.
 * All endpoints require authentication via Bearer token.
 */
class StatsController extends Controller
{
    use ApiResponser;

    /**
     * Dashboard aggregate stats
     *
     * Read-only aggregates with NO metric logic of its own (owner one-logic
     * rule 2026-11-16): both modes delegate verbatim to the dashboard's two
     * shared stat builders, so every eligibility/outlier/fund-scope/sign-split
     * rule is inherited by construction.
     *
     * - mode=summary (default): IncidentStatsFooterData::build() over a
     *   footer-parity scope (non-Issue rows within the token user's year
     *   access) — the exact numbers the dashboard footer shows.
     * - mode=by_dimension: AnalyticsQueryService::buildSingleDataset() —
     *   metric × dimension breakdowns ({labels[], values[], raw_data[]}),
     *   scoped to the token user's year access (parity with the summary
     *   branch, 2026-11-21).
     *
     * @authenticated
     *
     * @queryParam mode string summary (default) or by_dimension. Example: summary
     * @queryParam metric string by_dimension only: count, avg_mttr, avg_mttr_days, avg_mtbf, sum_fund_loss, sum_potential_fund_loss, sum_recovered_fund, recovery_rate. Example: count
     * @queryParam dimension string by_dimension only: monthly, quarterly, severity, incident_type, status, pic, label, business_category, root_cause_category, responsible_team, fund_status. Example: severity
     * @queryParam start_date date Filter incidents from this date (inclusive). Example: 2026-01-01
     * @queryParam end_date date Filter incidents until this date (inclusive). Example: 2026-12-31
     * @queryParam severities string[] Severity values. Example: ["P1","P2"]
     * @queryParam incident_types string[] Incident type names. Example: ["Tech"]
     * @queryParam statuses string[] Incident status values. Example: ["Completed"]
     * @queryParam fund_statuses string[] Fund status values. Example: ["Confirmed loss"]
     *
     * @response 200 {
     *   "code": 200,
     *   "status": "Success",
     *   "message": "Stats retrieved successfully.",
     *   "data": {
     *     "totalCases": 42,
     *     "avgMttrMins": 96.5,
     *     "avgMttrDays": 3.2,
     *     "avgMtbf": 12.4,
     *     "totalPotentialFundLoss": 150000000,
     *     "totalFundLoss": 62000000,
     *     "totalRecoveredFund": 30000000
     *   }
     * }
     */
    public function index(StatsRequest $request)
    {
        try {
            if ($request->validated('mode') === 'by_dimension') {
                $filters = collect($request->validated())
                    ->only(['start_date', 'end_date', 'severities', 'incident_types', 'statuses', 'fund_statuses'])
                    ->all();

                return $this->successResponse(
                    app(AnalyticsQueryService::class)->buildSingleDataset(
                        $request->validated('metric'),
                        $request->validated('dimension'),
                        $filters,
                        $request->user() // same slice the summary branch applies — parity by construction
                    ),
                    'Stats retrieved successfully.'
                );
            }

            // Footer-parity scope: non-Issue rows within the token user's year
            // access — exactly IncidentStatsFooterData::baseQuery()'s contract,
            // with API filters. build() never falls back to request()-derived
            // Filament filters because an explicit Builder is always passed.
            $query = Incident::query()
                ->where('classification', '!=', IncidentClassification::Issue->value)
                ->applyUserYearAccess($request->user());

            if ($request->filled('start_date')) {
                $query->where('incident_date', '>=', $request->date('start_date')->startOfDay());
            }
            if ($request->filled('end_date')) {
                $query->where('incident_date', '<=', $request->date('end_date')->endOfDay());
            }
            if ($request->filled('severities')) {
                $query->whereIn('severity', $request->validated('severities'));
            }
            if ($request->filled('incident_types')) {
                $query->whereIn('incident_type', $request->validated('incident_types'));
            }
            if ($request->filled('statuses')) {
                $query->whereIn('incident_status', $request->validated('statuses'));
            }
            if ($request->filled('fund_statuses')) {
                $query->whereIn('fund_status', $request->validated('fund_statuses'));
            }

            return $this->successResponse(app(IncidentStatsFooterData::class)->build($query), 'Stats retrieved successfully.');
        } catch (Exception $e) {
            Log::error('Failed to retrieve stats.', ['error' => $e->getMessage()]);

            return $this->errorResponse('Failed to retrieve stats.', 500);
        }
    }
}
