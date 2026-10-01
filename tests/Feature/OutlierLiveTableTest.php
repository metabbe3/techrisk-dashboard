<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Filament\Resources\IssueResource\Pages\ListIssues;
use App\Models\Incident;
use App\Models\Label;
use App\Models\User;
use App\Services\Markdown\IncidentMarkdownExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Owner rule (2026-10-01) on the LIVE tables + markdown: an Outlier-tagged
 * row keeps its place in the table, its MTTR/MTBF cells read "Outlier", and
 * markdown does not swallow a real 0 MTTR (truthy check bug).
 */
class OutlierLiveTableTest extends TestCase
{
    use RefreshDatabase;

    private function seedOutlier(): Incident
    {
        // now(), not a fixed January date: the table's default quick_period
        // filter scopes to the current period, hiding old rows.
        $b = Incident::factory()->create([
            'classification' => 'Incident',
            'severity' => 'P1',
            'fund_status' => 'Non fundLoss',
            'incident_date' => now()->startOfDay()->addHours(10),
            'stop_bleeding_at' => now()->startOfDay()->addHours(12),
        ]);
        $b->labels()->attach(Label::firstOrCreate(['name' => Label::OUTLIER]));

        return $b->fresh();
    }

    public function test_incidents_table_renders_outlier_cells(): void
    {
        $this->seedOutlier();

        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo('view incidents');

        $html = Livewire::actingAs($user)
            ->test(ListIncidents::class)
            ->html();

        // Cell markup only: the labels filter's select options also contain
        // the word "Outlier" (JSON.parse), but never as rendered cell text.
        $this->assertMatchesRegularExpression(
            '/>\s*Outlier\s*</',
            (string) $html,
            'MTTR/MTBF cells must render the Outlier literal'
        );
    }

    public function test_issues_table_renders_outlier_cells(): void
    {
        $b = Incident::factory()->create([
            'classification' => 'Issue',
            'severity' => 'P1',
            'incident_date' => now()->startOfDay()->addHours(10),
            'stop_bleeding_at' => now()->startOfDay()->addHours(12),
        ]);
        $b->labels()->attach(Label::firstOrCreate(['name' => Label::OUTLIER]));

        Permission::firstOrCreate(['name' => 'view issues']);
        $user = User::factory()->create();
        $user->givePermissionTo('view issues');

        $html = Livewire::actingAs($user)
            ->test(ListIssues::class)
            ->html();

        $this->assertMatchesRegularExpression(
            '/>\s*Outlier\s*</',
            (string) $html,
            'MTTR/MTBF cells must render the Outlier literal'
        );
    }

    public function test_markdown_renders_zero_mttr(): void
    {
        $incident = Incident::factory()->createQuietly([
            'classification' => 'Incident',
            'severity' => 'P1',
            'mttr' => 0,
        ]);

        $markdown = (new IncidentMarkdownExporter)->generate($incident->fresh());

        $this->assertStringContainsString('MTTR', $markdown);
    }
}
