<?php

namespace App\Filament\Widgets;

use App\Enums\FundStatus;
use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\Incident;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FundLossTrendChart extends ChartWidget
{
    use InteractsWithDashboardFilters;

    protected static ?string $heading = 'Fund Loss Trend';

    protected int|string|array $columnSpan = 6;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getData(): array
    {
        $cacheKey = 'fund_loss_trend_'.md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'year' => now()->year,
        ]));

        $data = Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $query = Incident::select(
                DB::raw('SUM(fund_loss) as total_fund_loss'),
                DB::raw('MONTH(incident_date) as month')
            )
                ->where(fn ($q) => $q->whereNull('fund_status')->orWhereNotIn('fund_status', FundStatus::EXCLUDED_FROM_COUNTS))
                ->groupBy('month');

            if ($this->start_date && $this->end_date) {
                $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            } else {
                $query->whereYear('incident_date', now()->year);
            }

            return $query->pluck('total_fund_loss', 'month')->all();
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
                    'label' => 'Fund Loss',
                    'data' => $values,
                    'borderColor' => 'rgba(244, 63, 94, 1)',
                    'backgroundColor' => 'rgba(244, 63, 94, 0.15)',
                    'fill' => true,
                    'tension' => 0.4,
                    'pointBackgroundColor' => 'rgba(244, 63, 94, 1)',
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
