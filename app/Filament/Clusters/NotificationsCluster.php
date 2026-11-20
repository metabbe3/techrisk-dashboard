<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;

class NotificationsCluster extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?int $navigationSort = 11;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    // The cluster root route served the gated NotificationCenter with a fatal
    // before its own canAccess (team-viewer audit, owner 2026-11-20) — gate
    // the whole cluster for the restricted role. The sidebar-footer
    // notification BELL is separate and keeps working for everyone.
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->isTeamViewer();
    }
}
