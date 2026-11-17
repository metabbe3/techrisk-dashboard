<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Owner 2026-11-17: the Summary used to be the table's contentFooter, so it
 * lived INSIDE the table's horizontal scroll container and moved with it.
 * It must render as its own block above the tabs, collapsible.
 */
class IncidentPageSummaryLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('view incidents');

        return $user;
    }

    private function seedIncident(): void
    {
        Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => now()->startOfDay(),
        ]);
    }

    public function test_the_summary_renders_above_the_tabs_outside_the_table_scroll(): void
    {
        $this->seedIncident();

        $html = Livewire::actingAs($this->manager())
            ->test(\App\Filament\Resources\IncidentResource\Pages\ListIncidents::class)
            ->assertSuccessful()
            ->html();

        $summaryPos = mb_strpos($html, 'fi-page-header-widgets');
        $tabsPos = mb_strpos($html, 'fi-tabs');

        $this->assertNotFalse($summaryPos, 'the summary must render as a page header widget (outside the table scroll)');
        $this->assertNotFalse($tabsPos, 'the tabs must still render');
        $this->assertLessThan($tabsPos, $summaryPos, 'order must be summary, then tabs, then table');
    }

    public function test_the_summary_section_is_collapsible(): void
    {
        $this->seedIncident();

        Livewire::actingAs($this->manager())
            ->test(\App\Filament\Resources\IncidentResource\Pages\ListIncidents::class)
            ->assertSuccessful()
            ->assertSeeHtml('x-on:toggle-section.window');
    }
}
