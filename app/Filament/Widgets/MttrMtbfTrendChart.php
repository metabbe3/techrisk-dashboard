<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class MttrMtbfTrendChart extends ChartWidget
{
    protected static ?string $heading = 'MTTR/MTBF Trend';

    protected int|string|array $columnSpan = 6;

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getData(): array
    {
        $cacheKey = 'mttr_mtbf_trend_' . md5(json_encode([
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'year' => now()->year,
        ]));

        $data = Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $query = Incident::select(
                DB::raw('AVG(mttr) as avg_mttr'),
                DB::raw('AVG(mtbf) as avg_mtbf'),
                DB::raw('MONTH(incident_date) as month')
            )
                ->groupBy('month');

            if ($this->start_date && $this->end_date) {
                $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            } else {
                $query->whereYear('incident_date', now()->year);
            }

            return $query->get()->keyBy('month');
        });

        $labels = [];
        $mttr_values = [];
        $mtbf_values = [];
        for ($i = 1; $i <= 12; $i++) {
            $labels[] = Carbon::create()->month($i)->format('M');
            $monthData = $data->get($i);
            $mttr_values[] = $monthData?->avg_mttr ?? 0;
            $mtbf_values[] = $monthData?->avg_mtbf ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'MTTR (minutes)',
                    'data' => $mttr_values,
                    'borderColor' => '#36A2EB',
                    'backgroundColor' => '#9BD0F5',
                ],
                [
                    'label' => 'MTBF (days)',
                    'data' => $mtbf_values,
                    'borderColor' => '#FFCE56',
                    'backgroundColor' => '#FFF2C6',
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

    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
