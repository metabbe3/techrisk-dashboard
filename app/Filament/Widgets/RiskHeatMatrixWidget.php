<?php

namespace App\Filament\Widgets;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\Incident;
use Carbon\Carbon;
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
                ->where('classification', IncidentClassification::Incident->value)
                ->whereIn('severity', Severity::METRIC_ELIGIBLE)
                ->excludedFromCounts()
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

        if (! is_array($recurrenceData) || ! isset($recurrenceData['matches']) || ! is_array($recurrenceData['matches'])) {
            return 1;
        }

        $matches = $recurrenceData['matches'];

        if (empty($matches)) {
            return 1;
        }

        $qualityScore = collect($matches)->reduce(function ($carry, $match) {
            $similarity = $match['similarity'] ?? ($match['score'] ?? 0) / 10;

            return $carry + min(max($similarity, 0), 1);
        }, 0);

        $timeDecayScore = collect($matches)->reduce(function ($carry, $match) {
            $date = $match['incident_date'] ?? null;
            if (! $date) {
                return $carry;
            }

            $daysDiff = now()->diffInDays(Carbon::parse($date));
            $decayFactor = exp(-$daysDiff / 365);

            return $carry + $decayFactor;
        }, 0);

        $severityScore = collect($matches)->reduce(function ($carry, $match) {
            return $carry + $this->severityWeight($match['severity'] ?? 'P4') / 10;
        }, 0);

        $combined = ($qualityScore * 0.4) + ($timeDecayScore * 0.35) + ($severityScore * 0.25);

        return match (true) {
            $combined >= 3.5 => 5,
            $combined >= 2.5 => 4,
            $combined >= 1.5 => 3,
            $combined >= 0.7 => 2,
            default => 1,
        };
    }

    private function severityWeight(string $severity): int
    {
        return match ($severity) {
            'P1' => 10,
            'P2' => 8,
            'P3', 'X1' => 6,
            'P4', 'X2' => 4,
            'X3', 'X4', 'G' => 3,
            default => 2,
        };
    }

    protected function calculateImpact(Incident $incident): int
    {
        $severity = $incident->severity;
        $fundLoss = (float) ($incident->fund_loss ?? 0);

        if ($severity === Severity::P1 || $fundLoss > 100000000) {
            return 5;
        }
        if ($severity === Severity::P2) {
            return 4;
        }
        if ($severity === Severity::P3 || in_array($severity, [Severity::X1, Severity::X2])) {
            return 3;
        }
        if ($severity === Severity::P4 && $fundLoss > 0) {
            return 2;
        }
        if (in_array($severity, [Severity::X3, Severity::X4])) {
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
