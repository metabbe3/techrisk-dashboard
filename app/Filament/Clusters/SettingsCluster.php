<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

class SettingsCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?int $navigationSort = 13;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    // The cluster root item rendered even when every member was gated away
    // (team-viewer audit, owner 2026-11-20) — gate the cluster itself on the
    // union of its members' permissions.
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->canAny([
            'manage incidents',
            'view incident types',
            'view labels',
            'manage api tokens',
        ]);
    }
}
