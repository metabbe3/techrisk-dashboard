<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Governance\GovernanceKpiService;
use App\Traits\ApiResponser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/governance — the DingTalk agent's read over the governance KPIs.
 * ZERO new metric math: it returns GovernanceKpiService::summary() verbatim
 * (the same builder the Filament Governance page shows) and, with
 * series=1, lagByQuarter()'s chart series. One-logic rule — the bot cannot
 * drift from the page.
 */
class GovernanceController extends Controller
{
    use ApiResponser;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'series' => ['nullable', 'boolean'],
        ]);

        $service = isset($data['year'])
            ? new GovernanceKpiService("{$data['year']}-01-01", "{$data['year']}-12-31")
            : new GovernanceKpiService;

        $payload = $service->summary();
        if (! empty($data['series'])) {
            $payload['lag_by_quarter'] = $service->lagByQuarter();
        }

        return $this->successResponse($payload, 'Governance KPIs retrieved.');
    }
}
