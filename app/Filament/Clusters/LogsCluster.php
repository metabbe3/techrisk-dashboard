<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

class LogsCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Logs';

    protected static ?int $navigationSort = 15;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
