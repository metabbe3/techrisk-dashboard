<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class FundLossTrendChart extends ChartWidget
{
    protected static ?string $heading = 'Fund Loss Trend';

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getData(): array
    {
        $query = Incident::select(
            DB::raw('SUM(fund_loss) as total_fund_loss'),
            DB::raw('MONTH(incident_date) as month')
        )
            ->groupBy('month');

        if ($this->start_date && $this->end_date) {
            $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
        } else {
            $query->whereYear('incident_date', now()->year);
        }

        $data = $query->pluck('total_fund_loss', 'month')->all();

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
                    'borderColor' => '#FF6384',
                    'backgroundColor' => '#FFB1C1',
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
