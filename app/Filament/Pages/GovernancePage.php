<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Governance scorecard (VP TechOps PDR §10): register completeness, RCA SLA,
 * overdue actions, recurrence, detection-lag analytics. All KPIs come from
 * GovernanceKpiService — no formulas live here.
 */
class GovernancePage extends Page
{
    protected static ?string $navigationIcon = null; // icon dropped: member renders as a cluster tab

    protected static ?string $navigationLabel = 'Governance';

    protected static ?string $title = 'Governance Scorecard';

    protected static ?string $cluster = \App\Filament\Clusters\ReportsCluster::class;

    protected static \Filament\Pages\SubNavigationPosition $subNavigationPosition = \Filament\Pages\SubNavigationPosition::Top;

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.governance';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage incidents') ?? false;
    }

    /** @return array<class-string> */
    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\GovernanceKpiOverview::class,
            \App\Filament\Widgets\DetectionLagChart::class,
            \App\Filament\Widgets\RootCauseFundParetoChart::class,
        ];
    }
}
