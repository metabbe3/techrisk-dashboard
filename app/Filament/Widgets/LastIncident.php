<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Incident;
use Carbon\Carbon;

class LastIncident extends BaseWidget
{

    protected function getStats(): array
    {
        $lastIncident = Incident::latest()->first();
        $days = $lastIncident ? Carbon::now()->diffInDays($lastIncident->created_at) : 0;

        return [
            Stat::make('Last Incident', $days . ' days ago')
                ->description('Days since the last incident')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
        ];
    }
}
