<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LastIncident extends BaseWidget
{
    protected int|string|array $columnSpan = [
        'md' => 4,
        'xl' => 4,
    ];

    protected function getStats(): array
    {
        $lastIncident = Incident::where('classification', 'Incident')
            ->latest('incident_date')
            ->first();

        $days = 0;
        if ($lastIncident && $lastIncident->incident_date) {
            $incidentDate = Carbon::parse($lastIncident->incident_date)->startOfDay();
            $today = Carbon::now()->startOfDay();
            // Prevent negative values if incident date is in the future
            $days = $incidentDate->gt($today) ? 0 : $today->diffInDays($incidentDate);
        }

        $label = $days === 0 ? 'No recent incident' : $days.' days ago';

        return [
            Stat::make('Last Incident', $label)
                ->description('Days since the last incident')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
        ];
    }
}
