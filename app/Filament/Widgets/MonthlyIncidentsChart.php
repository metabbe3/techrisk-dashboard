<?php

namespace App\Filament\Widgets;

use App\Enums\IncidentClassification;
use App\Filament\Concerns\HasChartColors;
use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\Incident;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class MonthlyIncidentsChart extends ChartWidget
{
    use HasChartColors, InteractsWithDashboardFilters;

    protected static ?string $heading = 'Monthly Incidents';

    protected int|string|array $columnSpan = 4;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getData(): array
    {
        $cacheKey = 'monthly_incidents_'.md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'year' => now()->year,
        ]));

        $data = Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $query = Incident::selectRaw('MONTH(incident_date) as month, COUNT(*) as count')
                ->where('classification', IncidentClassification::Incident->value)
                ->excludedFromCounts();

            if ($this->start_date && $this->end_date) {
                $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            } else {
                $query->whereYear('incident_date', now()->year);
            }

            $query->groupBy('month')->orderBy('month');

            return $query->pluck('count', 'month')->all();
        });

        $labels = [];
        $values = [];
        for ($i = 1; $i <= 12; $i++) {
            $labels[] = Carbon::create()->month($i)->format('M');
            $values[] = $data[$i] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Incidents',
                    'data' => $values,
                    'backgroundColor' => self::chartColors(12),
                    'borderColor' => self::chartBorderColors(12),
                    'borderWidth' => 2,
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getColumnSpan(): int|string|array
    {
        return 4;
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'grid' => [
                        'color' => 'rgba(0, 0, 0, 0.06)',
                    ],
                ],
            ],
            'layout' => [
                'padding' => 10,
            ],
        ];
    }
}
