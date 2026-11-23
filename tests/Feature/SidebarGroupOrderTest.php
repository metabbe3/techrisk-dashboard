<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Clusters\AiCluster;
use App\Filament\Clusters\LogsCluster;
use App\Filament\Clusters\NotificationsCluster;
use App\Filament\Clusters\ReportsCluster;
use App\Filament\Clusters\SettingsCluster;
use App\Filament\Clusters\UserManagementCluster;
use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Owner 2026-11-18: the sidebar groups render in the order the panel
 * REGISTERS them — NavigationManager sorts each group by array_search of
 * its index in ->navigationGroups([...]) (unlisted groups fall after every
 * listed one). Pinning the panel's registered order IS pinning the sidebar
 * order: Dashboard sits above all groups (the panel home, ungrouped).
 *
 * 2026-11-20 merge (main b01ace8): same-meaning groups became CLUSTERS —
 * only Operations stays a plain group, and the cluster order comes from
 * each cluster's $navigationSort constant (10..15). This pins both halves:
 * the registered group list and the cluster sort sequence.
 */
class SidebarGroupOrderTest extends TestCase
{
    public function test_the_sidebar_groups_render_in_the_owner_order(): void
    {
        $groups = Filament::getPanel('admin')->getNavigationGroups();

        $labels = array_map(
            fn ($group) => $group instanceof \Filament\Navigation\NavigationGroup
                ? $group->getLabel()
                : (string) $group,
            array_values($groups),
        );

        $this->assertSame(
            ['Operations'],
            $labels,
            'Operations is the only plain group — every other nav area is a cluster now.',
        );
    }

    public function test_the_cluster_sort_order_owns_the_sidebar_sequence(): void
    {
        $sorts = [
            'AI' => new \ReflectionClass(AiCluster::class)->getStaticPropertyValue('navigationSort'),
            'Reports' => new \ReflectionClass(ReportsCluster::class)->getStaticPropertyValue('navigationSort'),
            'Notifications' => new \ReflectionClass(NotificationsCluster::class)->getStaticPropertyValue('navigationSort'),
            'Settings' => new \ReflectionClass(SettingsCluster::class)->getStaticPropertyValue('navigationSort'),
            'User Management' => new \ReflectionClass(UserManagementCluster::class)->getStaticPropertyValue('navigationSort'),
            'Logs' => new \ReflectionClass(LogsCluster::class)->getStaticPropertyValue('navigationSort'),
        ];

        asort($sorts);

        // Owner 2026-11-23: Settings sits BELOW Logs.
        $this->assertSame(
            ['AI', 'Reports', 'Notifications', 'User Management', 'Logs', 'Settings'],
            array_keys($sorts),
            'The clusters render after Operations in this order — update the cluster $navigationSort constants, not this test.',
        );
    }
}
