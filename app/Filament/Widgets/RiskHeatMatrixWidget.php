<?php

namespace App\Filament\Widgets;

use App\Enums\FundStatus;
use App\Enums\Severity;
use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\Incident;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class RiskHeatMatrixWidget extends Widget
{
    use InteractsWithDashboardFilters;

    protected static string $view = 'filament.widgets.risk-heat-matrix';

    protected static ?string $heading = 'Risk Heat Matrix';

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected int|string|array $columnSpan = 'full';

    public array $matrix = [];

    public int $totalIncidents = 0;

    public function mount(): void
    {
        $this->computeMatrix();
    }

    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
        $this->computeMatrix();
    }

    protected function computeMatrix(): void
    {
        $cacheKey = 'risk_heat_matrix_'.md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'year' => now()->year,
        ]));

        $cached = Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $incidents = Incident::query()
                ->where('classification', 'Incident')
                ->whereIn('severity', Severity::METRIC_ELIGIBLE)
                ->where(fn ($q) => $q->whereNull('fund_status')
                    ->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS))
                ->when($this->start_date && $this->end_date,
                    fn ($q) => $q->whereBetween('incident_date', [$this->start_date, $this->end_date]),
                    fn ($q) => $q->whereYear('incident_date', now()->year))
                ->get(['id', 'severity', 'fund_loss', 'recurrence_data']);

            $matrix = [];
            for ($l = 1; $l <= 5; $l++) {
                for ($i = 1; $i <= 5; $i++) {
                    $matrix[$l][$i] = ['count' => 0, 'ids' => []];
                }
            }

            foreach ($incidents as $incident) {
                $likelihood = $this->calculateLikelihood($incident);
                $impact = $this->calculateImpact($incident);
                $matrix[$likelihood][$impact]['count']++;
                $matrix[$likelihood][$impact]['ids'][] = $incident->id;
            }

            return ['matrix' => $matrix, 'total' => $incidents->count()];
        });

        $this->matrix = $cached['matrix'];
        $this->totalIncidents = $cached['total'];
    }

    protected function calculateLikelihood(Incident $incident): int
    {
        $recurrenceData = $incident->recurrence_data;
        $matchCount = 0;

        if (is_array($recurrenceData) && isset($recurrenceData['matches']) && is_array($recurrenceData['matches'])) {
            $matchCount = count($recurrenceData['matches']);
        }

        return match (true) {
            $matchCount >= 6 => 5,
            $matchCount >= 4 => 4,
            $matchCount >= 2 => 3,
            $matchCount >= 1 => 2,
            default => 1,
        };
    }

    protected function calculateImpact(Incident $incident): int
    {
        $severity = $incident->severity;
        $fundLoss = (float) ($incident->fund_loss ?? 0);

        if ($severity === 'P1' || $fundLoss > 100000000) {
            return 5;
        }
        if ($severity === 'P2') {
            return 4;
        }
        if ($severity === 'P3' || in_array($severity, ['X1', 'X2'])) {
            return 3;
        }
        if ($severity === 'P4' && $fundLoss > 0) {
            return 2;
        }
        if (in_array($severity, ['X3', 'X4'])) {
            return 2;
        }

        return 1;
    }

    public function getCellColor(int $likelihood, int $impact): string
    {
        $riskScore = $likelihood * $impact;

        return match (true) {
            $riskScore >= 20 => 'bg-red-500 text-white',
            $riskScore >= 13 => 'bg-orange-500 text-white',
            $riskScore >= 6 => 'bg-yellow-400 text-gray-900',
            $riskScore >= 1 => 'bg-green-400 text-gray-900',
            default => 'bg-gray-100 text-gray-400',
        };
    }

    public function getRiskLabel(int $likelihood, int $impact): string
    {
        $riskScore = $likelihood * $impact;

        return match (true) {
            $riskScore >= 20 => 'Critical',
            $riskScore >= 13 => 'High',
            $riskScore >= 6 => 'Medium',
            default => 'Low',
        };
    }

    public static function canView(): bool
    {
        return auth()->user()?->can('manage incidents') ?? false;
    }
}
