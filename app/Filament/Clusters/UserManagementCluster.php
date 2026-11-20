<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

class UserManagementCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'User Management';

    protected static ?int $navigationSort = 14;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
