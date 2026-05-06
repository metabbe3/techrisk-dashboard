<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\Incident;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MttrMtbfTrendChart extends ChartWidget
{
    use InteractsWithDashboardFilters;

    protected static ?string $heading = 'MTTR/MTBF Trend';

    protected int|string|array $columnSpan = 6;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getData(): array
    {
        $cacheKey = 'mttr_mtbf_trend_v2_'.md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'year' => now()->year,
        ]));

        $data = Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $baseQuery = Incident::where('classification', '!=', 'Issue');

            if ($this->start_date && $this->end_date) {
                $baseQuery->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            } else {
                $baseQuery->whereYear('incident_date', now()->year);
            }

            // MTTR per month — average of positive (non-fund-loss) values only
            $mttrData = $baseQuery->clone()
                ->select(DB::raw('MONTH(incident_date) as month'), DB::raw('AVG(CASE WHEN mttr >= 0 THEN mttr ELSE NULL END) as avg_mttr'))
                ->groupBy(DB::raw('MONTH(incident_date)'))
                ->get()
                ->keyBy('month');

            // MTBF per month — single query grouped by month
            $mtbfRows = $baseQuery->clone()
                ->whereNotIn('severity', ['Non Incident', 'G'])
                ->select(DB::raw('MONTH(incident_date) as month'), DB::raw('MIN(incident_date) as min_date'), DB::raw('MAX(incident_date) as max_date'), DB::raw('COUNT(*) as cnt'))
                ->groupBy(DB::raw('MONTH(incident_date)'))
                ->get();

            $mtbfData = [];
            foreach ($mtbfRows as $row) {
                if ($row->cnt > 1) {
                    $first = Carbon::parse($row->min_date)->startOfDay();
                    $last = Carbon::parse($row->max_date)->startOfDay();
                    $mtbfData[$row->month] = round($first->diffInDays($last) / ($row->cnt - 1), 2);
                } else {
                    $mtbfData[$row->month] = 0;
                }
            }

            return ['mttr' => $mttrData, 'mtbf' => $mtbfData];
        });

        $labels = [];
        $mttr_values = [];
        $mtbf_values = [];
        for ($i = 1; $i <= 12; $i++) {
            $labels[] = Carbon::create()->month($i)->format('M');
            $mttrRow = $data['mttr']->get($i);
            $mttr_values[] = $mttrRow ? round((float) $mttrRow->avg_mttr, 2) : 0;
            $mtbf_values[] = $data['mtbf'][$i] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'MTTR (minutes)',
                    'data' => $mttr_values,
                    'borderColor' => 'rgba(14, 165, 233, 1)',
                    'backgroundColor' => 'rgba(14, 165, 233, 0.12)',
                    'fill' => true,
                    'tension' => 0.4,
                    'pointBackgroundColor' => 'rgba(14, 165, 233, 1)',
                    'pointBorderColor' => '#fff',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 4,
                    'pointHoverRadius' => 6,
                ],
                [
                    'label' => 'MTBF (days)',
                    'data' => $mtbf_values,
                    'borderColor' => 'rgba(139, 92, 246, 1)',
                    'backgroundColor' => 'rgba(139, 92, 246, 0.12)',
                    'fill' => true,
                    'tension' => 0.4,
                    'pointBackgroundColor' => 'rgba(139, 92, 246, 1)',
                    'pointBorderColor' => '#fff',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 4,
                    'pointHoverRadius' => 6,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getColumnSpan(): int|string|array
    {
        return 6;
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                    'labels' => [
                        'usePointStyle' => true,
                        'padding' => 16,
                    ],
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
