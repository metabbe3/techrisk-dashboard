<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Models\Incident;
use App\Models\User;
use App\Services\Ai\PostMortemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * D3/D4: Generate Retro header action stores retro_markdown /
 * retro_generated_at / retro_model (regenerate overwrites; blank result →
 * error notification), and the Retrospective section renders only once a
 * retro exists.
 */
class ViewIncidentRetroTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $user = User::factory()->create();
        $user->assignRole('admin'); // skips applyUserYearAccess record scoping
        $user->givePermissionTo(['view incidents', 'manage incidents']);

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

    private function mockRetro(?string $markdown, string $model = 'retro-model'): void
    {
        $this->mock(PostMortemService::class, function ($mock) use ($markdown, $model) {
            $mock->shouldReceive('generateAsMarkdown')->andReturn($markdown);
            $mock->shouldReceive('resolvedModel')->andReturn($model);
        });
    }

    public function test_generate_retro_action_stores_columns_and_notifies(): void
    {
        $incident = $this->makeIncident();
        $this->mockRetro("# Retrospective — {$incident->no}\n\n## Executive Summary\n\nPool exhaustion.");

        Livewire::actingAs($this->manager())
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->callAction('generate_retro')
            ->assertNotified();

        $incident->refresh();
        $this->assertStringStartsWith("# Retrospective — {$incident->no}", (string) $incident->retro_markdown);
        $this->assertStringContainsString('## Executive Summary', (string) $incident->retro_markdown);
        $this->assertNotNull($incident->retro_generated_at);
        $this->assertSame('retro-model', $incident->retro_model);
    }

    public function test_generate_retro_errors_when_ai_returns_nothing(): void
    {
        $incident = $this->makeIncident();
        $this->mockRetro(null);

        Livewire::actingAs($this->manager())
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->callAction('generate_retro');

        $incident->refresh();
        $this->assertNull($incident->retro_markdown);
        $this->assertNull($incident->retro_generated_at);
    }

    public function test_regenerate_overwrites_the_stored_retro(): void
    {
        $incident = $this->makeIncident(['retro_markdown' => '# OLD', 'retro_model' => 'old-model']);
        $this->mockRetro('# NEW RETRO');

        Livewire::actingAs($this->manager())
            ->test(ViewIncident::class, ['record' => (string) $incident->id])
            ->callAction('generate_retro')
            ->assertNotified();

        $this->assertSame('# NEW RETRO', $incident->refresh()->retro_markdown);
        $this->assertSame('retro-model', $incident->refresh()->retro_model);
    }

    public function test_retrospective_section_renders_only_when_retro_exists(): void
    {
        $with = $this->makeIncident(['retro_markdown' => 'Pool exhaustion post-mortem content.']);
        $without = $this->makeIncident();
        $user = $this->manager();

        $html = Livewire::actingAs($user)
            ->test(ViewIncident::class, ['record' => (string) $with->id])
            ->html();
        $this->assertStringContainsString('Retrospective', $html);
        $this->assertStringContainsString('Pool exhaustion post-mortem content.', $html);

        $html = Livewire::actingAs($user)
            ->test(ViewIncident::class, ['record' => (string) $without->id])
            ->html();
        $this->assertStringNotContainsString('Retrospective', $html);
    }
}
