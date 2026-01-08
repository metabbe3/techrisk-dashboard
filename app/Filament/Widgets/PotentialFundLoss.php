<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Incident;
use App\Models\StatusUpdate;

class PotentialFundLoss extends BaseWidget
{
    protected function getStats(): array
    {
        $openCases = Incident::whereHas('latestStatusUpdate', function ($query) {
            $query->whereNotIn('status', ['Closed', 'Resolved', 'Recovered']);
        })->sum('potential_fund_loss');

        return [
            Stat::make('Potential Fund Loss', 'IDR ' . number_format($openCases, 2, ',', '.'))
                ->description('Total potential fund loss from all open cases')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('danger'),
        ];
    }
}
