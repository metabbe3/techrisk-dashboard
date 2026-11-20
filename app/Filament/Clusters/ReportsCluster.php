<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

class ReportsCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?int $navigationSort = 11;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
