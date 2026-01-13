<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\IncidentsBySeverityChart;
use App\Filament\Widgets\IncidentsByTypeChart;
use App\Filament\Widgets\IncidentStatsOverview;
use App\Filament\Widgets\MonthlyIncidentsChart;
use App\Filament\Widgets\OpenIncidents;
use App\Filament\Widgets\RecentIncidents;
use App\Filament\Widgets\IncidentsByPicChart;
use App\Filament\Widgets\FundLossTrendChart;
use App\Filament\Widgets\MttrMtbfTrendChart;
use App\Filament\Widgets\IncidentsByLabelChart;
use App\Filament\Widgets\ActionImprovementsOverview;
use App\Models\UserDashboardPreference;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    public function getColumns(): int | string | array
    {
        return 12;
    }

    public function getWidgets(): array
    {
        $user = Auth::user();

        // Try to get user's widget preferences
        $userWidgets = UserDashboardPreference::where('user_id', $user->id)
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->pluck('widget_class')
            ->toArray();

        // If user has preferences set, return those
        if (!empty($userWidgets)) {
            return $userWidgets;
        }

        // Initialize default preferences for first-time users
        UserDashboardPreference::initializeDefaultsForUser($user);

        // Return default widgets
        return [
            IncidentStatsOverview::class,
            ActionImprovementsOverview::class,
            MonthlyIncidentsChart::class,
            IncidentsBySeverityChart::class,
            IncidentsByTypeChart::class,
            OpenIncidents::class,
            RecentIncidents::class,
            IncidentsByPicChart::class,
            FundLossTrendChart::class,
            MttrMtbfTrendChart::class,
            IncidentsByLabelChart::class,
        ];
    }
}
