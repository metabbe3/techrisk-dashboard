<?php

declare(strict_types=1);

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Owner 2026-11-18: the sidebar groups render in the order the panel
 * REGISTERS them — NavigationManager sorts each group by array_search of
 * its index in ->navigationGroups([...]) (unlisted groups fall after every
 * listed one). Pinning the panel's registered order IS pinning the sidebar
 * order: Dashboard sits above all groups (the panel home, ungrouped).
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
            ['Operations', 'Reports', 'AI', 'Notifications', 'User Management', 'Settings', 'System'],
            $labels,
            'The sidebar group order is owner-fixed — update the panel\'s navigationGroups(...) list, not this test.',
        );
    }
}
