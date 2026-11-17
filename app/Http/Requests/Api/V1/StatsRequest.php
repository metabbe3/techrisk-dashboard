<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Http\Requests\Api\ApiFormRequest;
use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/stats — aggregate dashboard metrics for the DingTalk agent.
 *
 * Zero metric logic here: the controller delegates to the two shared stat
 * builders (IncidentStatsFooterData / AnalyticsQueryService::buildSingleDataset)
 * and this request only guards the parameter vocabulary.
 */
class StatsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'mode' => ['nullable', 'string', 'in:summary,by_dimension'],
            'metric' => ['nullable', 'required_if:mode,by_dimension', 'string', Rule::in(array_keys(AnalyticsQueryService::METRICS))],
            'dimension' => ['nullable', 'required_if:mode,by_dimension', 'string', Rule::in(array_keys(AnalyticsQueryService::DIMENSIONS))],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'severities' => ['nullable', 'array', 'max:20'],
            'severities.*' => ['string', Rule::enum(Severity::class)],
            'incident_types' => ['nullable', 'array', 'max:20'],
            'incident_types.*' => ['string'],
            'statuses' => ['nullable', 'array', 'max:20'],
            'statuses.*' => ['string', Rule::enum(IncidentStatus::class)],
            'fund_statuses' => ['nullable', 'array', 'max:20'],
            'fund_statuses.*' => ['string', Rule::enum(FundStatus::class)],
        ];
    }
}
