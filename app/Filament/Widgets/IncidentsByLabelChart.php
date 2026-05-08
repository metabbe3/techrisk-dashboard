<?php

namespace App\Filament\Widgets;

use App\Enums\FundStatus;
use App\Filament\Concerns\HasChartColors;
use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\Label;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;

class IncidentsByLabelChart extends ChartWidget
{
    use HasChartColors, InteractsWithDashboardFilters;

    protected static ?string $heading = 'Incidents by Label';

    protected int|string|array $columnSpan = 6;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getData(): array
    {
        $cacheKey = 'incidents_by_label_'.md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'year' => now()->year,
        ]));

        $data = Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $query = Label::query()
                ->withCount(['incidents' => function ($query) {
                    $query->where(fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS));
                    if ($this->start_date && $this->end_date) {
                        $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
                    } else {
                        $query->whereYear('incident_date', now()->year);
                    }
                }])
                ->having('incidents_count', '>', 0);

            return $query->pluck('incidents_count', 'name');
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
        return 'bar'; // Changed from 'pie' to 'bar' for consistent height
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
                    'display' => false, // Hide legend for cleaner look
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
