<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

class AiCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'AI';

    protected static ?int $navigationSort = 10;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
