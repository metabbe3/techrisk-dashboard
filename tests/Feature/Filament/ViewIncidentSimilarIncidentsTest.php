<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Models\Incident;
use App\Models\IncidentSimilarIncident;
use App\Models\User;
use App\Services\Ai\SimilarIncidentResult;
use App\Services\Ai\SimilarIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * C1/C2: similar incidents surface on the incident VIEW page — a
 * server-rendered section over activeSimilarCards(), plus an on-demand
 * Detect Similar header action running the same pipeline + persist as the
 * edit-page button and the auto-detection job (owner 2026-10-01).
 */
class ViewIncidentSimilarIncidentsTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(bool $canManage = false): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $user = User::factory()->create();
        $user->assignRole('admin'); // skips applyUserYearAccess record scoping
        $user->givePermissionTo($canManage ? ['view incidents', 'manage incidents'] : ['view incidents']);

        return $user;
    }

    private function makeIncident(array $overrides = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'severity' => Severity::P2,
            'incident_status' => IncidentStatus::Completed,
            'classification' => IncidentClassification::Incident,
            'incident_date' => now()->subMonth(),
        ], $overrides));
    }

    private function linkSimilar(Incident $source, Incident $candidate, array $overrides = []): IncidentSimilarIncident
    {
        return IncidentSimilarIncident::create(array_merge([
            'incident_id' => $source->id,
            'similar_incident_id' => $candidate->id,
            'similarity' => 0.82,
            'match_type' => 'deep',
            'reasoning' => 'Same DB connection-pool exhaustion mechanism.',
        ], $overrides));
    }

    public function test_similar_section_lists_matches_with_score_and_match_type(): void
    {
        $source = $this->makeIncident();
        $candidate = $this->makeIncident(['title' => 'Gateway DB pool exhausted']);
        $this->linkSimilar($source, $candidate);

        $html = Livewire::actingAs($this->viewer())
            ->test(ViewIncident::class, ['record' => (string) $source->id])
            ->html();

        $this->assertStringContainsString('Similar Incidents', $html);
        $this->assertStringContainsString($candidate->no, $html);
        $this->assertStringContainsString('82%', $html);
        $this->assertStringContainsString('Deep', $html, 'match_type badge must render');
        $this->assertStringContainsString('Same DB connection-pool exhaustion mechanism.', $html);
    }

    public function test_similar_section_shows_hint_when_none_detected(): void
    {
        $source = $this->makeIncident();

        $html = Livewire::actingAs($this->viewer())
            ->test(ViewIncident::class, ['record' => (string) $source->id])
            ->html();

        $this->assertStringContainsString('Similar Incidents', $html);
        $this->assertStringContainsString('None detected yet', $html);
    }

    public function test_detect_similar_action_runs_pipeline_and_persists(): void
    {
        $source = $this->makeIncident();
        $candidate = $this->makeIncident(['title' => 'Gateway DB pool exhausted']);

        $this->mock(SimilarIncidentService::class, function ($mock) use ($source, $candidate) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('analyze')->andReturn(SimilarIncidentResult::success(
                matches: [[
                    'id' => $candidate->id,
                    'no' => $candidate->no,
                    'title' => $candidate->title,
                    'similarity' => 0.9,
                    'match_type' => 'deep',
                    'reasoning' => 'same mechanism',
                ]],
                model: 'REASONING-MODEL',
            ));
            $mock->shouldReceive('persist')->once()->with(
                \Mockery::on(fn ($arg) => $arg->is($source)),
                \Mockery::on(fn ($matches) => ($matches[0]['id'] ?? null) === $candidate->id),
            );
        });

        Livewire::actingAs($this->viewer(canManage: true))
            ->test(ViewIncident::class, ['record' => (string) $source->id])
            ->callAction('detect_similar_incidents')
            ->assertNotified();
    }

    public function test_detect_similar_action_hidden_for_view_only_users(): void
    {
        $source = $this->makeIncident();

        $this->mock(SimilarIncidentService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
        });

        // Review finding: the same pipeline over HTTP is gated by
        // can:manage incidents — a view-only user must not run it (paid
        // multi-call pipeline + persist() prunes/rewrites rows).
        Livewire::actingAs($this->viewer())
            ->test(ViewIncident::class, ['record' => (string) $source->id])
            ->assertActionHidden('detect_similar_incidents');
    }

    public function test_detect_similar_action_hidden_when_ai_unavailable(): void
    {
        $source = $this->makeIncident();

        $this->mock(SimilarIncidentService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturn(false);
        });

        Livewire::actingAs($this->viewer())
            ->test(ViewIncident::class, ['record' => (string) $source->id])
            ->assertActionHidden('detect_similar_incidents');
    }

    public function test_source_assignment_section_shows_all_pic_names(): void
    {
        // Final-review fix: TextEntry::make('pic.name') silently rendered
        // blank after the multi-PIC cutover (relation gone, data_get null).
        config(['broadcasting.default' => 'log']); // attach notifies admins → broadcast
        $source = $this->makeIncident();
        $pic1 = \App\Models\User::factory()->create(['name' => 'Rina Wijaya']);
        $pic2 = \App\Models\User::factory()->create(['name' => 'Budi Hartono']);
        $source->pics()->attach([$pic1->id, $pic2->id]);

        $html = Livewire::actingAs($this->viewer())
            ->test(ViewIncident::class, ['record' => (string) $source->id])
            ->html();

        // Scope to the infolist section — the page hero also shows PIC
        // names, which would mask a blank entry here.
        $start = strpos($html, 'Source &amp; Assignment') ?: strpos($html, 'Source & Assignment');
        $end = strpos($html, 'Categories &amp; Metrics') ?: strpos($html, 'Categories & Metrics');
        $this->assertNotFalse($start, 'Source & Assignment section must render');
        $section = substr($html, $start, $end !== false ? $end - $start : null);

        $this->assertStringContainsString('Rina Wijaya', $section);
        $this->assertStringContainsString('Budi Hartono', $section);
    }
}
