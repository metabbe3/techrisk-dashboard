<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\HasChartColors;
use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\Incident;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IncidentsByPicChart extends ChartWidget
{
    use HasChartColors, InteractsWithDashboardFilters;

    protected static ?string $heading = 'Incidents by Person In Charge';

    protected int|string|array $columnSpan = 6;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getData(): array
    {
        $cacheKey = 'incidents_by_pic_'.md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'year' => now()->year,
        ]));

        $data = Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $query = Incident::query()
                ->select('users.name as pic_name', DB::raw('count(incidents.id) as total'))
                ->join('users', 'incidents.pic_id', '=', 'users.id')
                ->excludedFromCounts()
                ->groupBy('users.name');

            if ($this->start_date && $this->end_date) {
                $query->whereBetween('incidents.incident_date', [$this->start_date, $this->end_date]);
            } else {
                $query->whereYear('incidents.incident_date', now()->year);
            }

            return $query->pluck('total', 'pic_name');
        });

        $count = $data->count();

        return [
            'datasets' => [
                [
                    'label' => 'Incidents',
                    'data' => $data->values()->all(),
                    'backgroundColor' => self::chartColors($count),
                    'borderColor' => self::chartBorderColors($count),
                    'borderWidth' => 1,
                    'borderRadius' => 4,
                ],
            ],
            'labels' => $data->keys()->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getColumnSpan(): int|string|array
    {
        return 6;
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'indexAxis' => 'y', // Horizontal bar for better space usage
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'grid' => [
                        'color' => 'rgba(0, 0, 0, 0.06)',
                    ],
                ],
                'y' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
            ],
        ];
    }
}
