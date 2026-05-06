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
        $cacheKey = 'mttr_mtbf_trend_v3_'.md5(json_encode([
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

            // MTTR Non Fund Loss per month (minutes) — average of positive values
            $mttrNonFundData = $baseQuery->clone()
                ->select(DB::raw('MONTH(incident_date) as month'), DB::raw('AVG(CASE WHEN mttr >= 0 THEN mttr ELSE NULL END) as avg_mttr'))
                ->groupBy(DB::raw('MONTH(incident_date)'))
                ->get()
                ->keyBy('month');

            // MTTR Fund Loss per month (days) — average of negative values (absolute)
            $mttrFundData = $baseQuery->clone()
                ->select(DB::raw('MONTH(incident_date) as month'), DB::raw('AVG(CASE WHEN mttr < 0 THEN ABS(mttr) ELSE NULL END) as avg_mttr'))
                ->groupBy(DB::raw('MONTH(incident_date)'))
                ->get()
                ->keyBy('month');

            // MTBF Non Fund Loss per month
            $mtbfNonFundRows = $baseQuery->clone()
                ->whereNotIn('severity', ['Non Incident', 'G'])
                ->where('fund_status', 'Non fundLoss')
                ->select(DB::raw('MONTH(incident_date) as month'), DB::raw('MIN(incident_date) as min_date'), DB::raw('MAX(incident_date) as max_date'), DB::raw('COUNT(*) as cnt'))
                ->groupBy(DB::raw('MONTH(incident_date)'))
                ->get();

            $mtbfNonFundData = [];
            foreach ($mtbfNonFundRows as $row) {
                if ($row->cnt > 1) {
                    $first = Carbon::parse($row->min_date)->startOfDay();
                    $last = Carbon::parse($row->max_date)->startOfDay();
                    $mtbfNonFundData[$row->month] = round($first->diffInDays($last) / ($row->cnt - 1), 2);
                } else {
                    $mtbfNonFundData[$row->month] = 0;
                }
            }

            // MTBF Fund Loss per month
            $mtbfFundRows = $baseQuery->clone()
                ->whereNotIn('severity', ['Non Incident', 'G'])
                ->whereIn('fund_status', ['Confirmed loss', 'Potential recovery'])
                ->select(DB::raw('MONTH(incident_date) as month'), DB::raw('MIN(incident_date) as min_date'), DB::raw('MAX(incident_date) as max_date'), DB::raw('COUNT(*) as cnt'))
                ->groupBy(DB::raw('MONTH(incident_date)'))
                ->get();

            $mtbfFundData = [];
            foreach ($mtbfFundRows as $row) {
                if ($row->cnt > 1) {
                    $first = Carbon::parse($row->min_date)->startOfDay();
                    $last = Carbon::parse($row->max_date)->startOfDay();
                    $mtbfFundData[$row->month] = round($first->diffInDays($last) / ($row->cnt - 1), 2);
                } else {
                    $mtbfFundData[$row->month] = 0;
                }
            }

            return [
                'mttr_non_fund' => $mttrNonFundData,
                'mttr_fund' => $mttrFundData,
                'mtbf_non_fund' => $mtbfNonFundData,
                'mtbf_fund' => $mtbfFundData,
            ];
        });

        $labels = [];
        $mttrNonFundValues = [];
        $mttrFundValues = [];
        $mtbfNonFundValues = [];
        $mtbfFundValues = [];
        for ($i = 1; $i <= 12; $i++) {
            $labels[] = Carbon::create()->month($i)->format('M');
            $mttrNonFundRow = $data['mttr_non_fund']->get($i);
            $mttrNonFundValues[] = $mttrNonFundRow ? round((float) $mttrNonFundRow->avg_mttr, 2) : 0;
            $mttrFundRow = $data['mttr_fund']->get($i);
            $mttrFundValues[] = $mttrFundRow ? round((float) $mttrFundRow->avg_mttr, 2) : 0;
            $mtbfNonFundValues[] = $data['mtbf_non_fund'][$i] ?? 0;
            $mtbfFundValues[] = $data['mtbf_fund'][$i] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'MTTR Non Fund Loss (mins)',
                    'data' => $mttrNonFundValues,
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
                    'label' => 'MTTR Fund Loss (days)',
                    'data' => $mttrFundValues,
                    'borderColor' => 'rgba(244, 63, 94, 1)',
                    'backgroundColor' => 'rgba(244, 63, 94, 0.12)',
                    'fill' => true,
                    'tension' => 0.4,
                    'pointBackgroundColor' => 'rgba(244, 63, 94, 1)',
                    'pointBorderColor' => '#fff',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 4,
                    'pointHoverRadius' => 6,
                ],
                [
                    'label' => 'MTBF Non Fund Loss (days)',
                    'data' => $mtbfNonFundValues,
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
                [
                    'label' => 'MTBF Fund Loss (days)',
                    'data' => $mtbfFundValues,
                    'borderColor' => 'rgba(245, 158, 11, 1)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.12)',
                    'fill' => true,
                    'tension' => 0.4,
                    'pointBackgroundColor' => 'rgba(245, 158, 11, 1)',
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
