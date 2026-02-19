<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ActionImprovementsOverview;
use App\Filament\Widgets\FundLossTrendChart;
use App\Filament\Widgets\IncidentsByLabelChart;
use App\Filament\Widgets\IncidentsByPicChart;
use App\Filament\Widgets\IncidentsBySeverityChart;
use App\Filament\Widgets\IncidentsByTypeChart;
use App\Filament\Widgets\IncidentStatsOverview;
use App\Filament\Widgets\MonthlyIncidentsChart;
use App\Filament\Widgets\MttrMtbfTrendChart;
use App\Filament\Widgets\OpenIncidents;
use App\Filament\Widgets\RecentIncidents;
use App\Filament\Widgets\TotalIncidents;
use App\Filament\Widgets\TotalIncidentsOnly;
use App\Models\UserDashboardPreference;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

class Dashboard extends BaseDashboard
{
    public function getColumns(): int|string|array
    {
        return 12;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('customize_widgets')
                ->label('Customize Widgets')
                ->icon('heroicon-o-view-columns')
                ->url(ManageDashboardWidgets::getUrl()),
        ];
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
        if (! empty($userWidgets)) {
            return $userWidgets;
        }

        // Initialize default preferences for first-time users
        UserDashboardPreference::initializeDefaultsForUser($user);

        // Return default widgets with organized layout
        return [
            // Row 1: Total Count Stats
            TotalIncidentsOnly::class,              // Total Incidents only
            TotalIncidents::class,                  // Total Issues (Incidents + Issues)

            // Row 2: Key Metrics Stats
            IncidentStatsOverview::class,
            ActionImprovementsOverview::class,

            // Row 2: Charts (3 charts, 4 columns each = 12 columns total)
            MonthlyIncidentsChart::class,          // 4 cols
            IncidentsBySeverityChart::class,        // 4 cols
            IncidentsByTypeChart::class,            // 4 cols

            // Row 3: Incident Tables (2 tables, 6 columns each = 12 columns total)
            OpenIncidents::class,                   // 6 cols
            RecentIncidents::class,                 // 6 cols

            // Row 4: Additional Charts
            FundLossTrendChart::class,              // 6 cols
            MttrMtbfTrendChart::class,              // 6 cols

            // Row 5: More Analysis
            IncidentsByPicChart::class,             // 6 cols
            IncidentsByLabelChart::class,           // 6 cols
        ];
    }
}
