<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

class NotificationsCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?int $navigationSort = 12;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
