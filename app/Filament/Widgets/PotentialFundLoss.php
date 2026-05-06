<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithDashboardFilters;
use App\Models\Incident;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PotentialFundLoss extends BaseWidget
{
    use InteractsWithDashboardFilters;

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 4,
    ];

    public ?string $start_date = null;

    public ?string $end_date = null;

    protected function getColumns(): int
    {
        return 1;
    }

    protected function getStats(): array
    {
        $query = Incident::query()->whereHas('latestStatusUpdate', function ($query) {
            $query->whereNotIn('status', ['Closed', 'Resolved', 'Recovered']);
        });

        $descriptionPeriod = 'this year';
        if ($this->start_date && $this->end_date) {
            $query->whereBetween('incident_date', [$this->start_date, $this->end_date]);
            $descriptionPeriod = 'in the selected period';
        } else {
            $query->whereYear('incident_date', now()->year);
        }

        $openCases = $query->sum('potential_fund_loss');

        return [
            Stat::make('Potential Fund Loss', 'IDR '.number_format($openCases, 2, ',', '.'))
                ->description('Open cases potential loss '.$descriptionPeriod)
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->chart([3, 5, 4, 7, 2, 6, 4, 5])
                ->color('danger'),
        ];
    }
}
