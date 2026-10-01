<?php

declare(strict_types=1);

namespace Tests\Feature\Markdown;

use App\Filament\Resources\AiAgentMemoryResource\Pages\ListAiAgentMemories;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Models\User;
use App\Services\Markdown\IncidentMarkdownCorpusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Incident memory (owner 2026-10-01): a persistent corpus at markdown/corpus
 * (index.md + one folder per incident) rebuilt by one button and an hourly
 * stale check, feeding AI agents as long-term memory. Scope mirrors the
 * AI-facing export rule: Incidents only, P1–P4/X1–X4; fund-status-excluded
 * rows stay (knowledge base, not a metrics count).
 */
class IncidentMarkdownCorpusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // corpus + cached doc fixtures stay on the fake
    }

    private function makeIncident(array $attrs = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'classification' => 'Incident', // factory randomizes it — pin it
            'severity' => 'P1',
            'incident_date' => '2040-01-15 10:00:00',
            'stop_bleeding_at' => '2040-01-15 12:00:00',
        ], $attrs));
    }

    private function cachedDoc(Incident $incident, string $filename, string $markdown): InvestigationDocument
    {
        $doc = InvestigationDocument::factory()->create([
            'incident_id' => $incident->id,
            'file_path' => 'documents/'.$filename,
            'original_filename' => $filename,
        ]);
        $doc->update([
            'markdown_path' => "markdown/documents/{$doc->id}.md",
            'markdown_conversion_status' => 'completed',
            'markdown_converted_at' => now(),
        ]);
        Storage::disk('local')->put($doc->markdown_path, $markdown);

        return $doc;
    }

    private function service(): IncidentMarkdownCorpusService
    {
        return app(IncidentMarkdownCorpusService::class);
    }

    public function test_refresh_writes_index_folders_and_manifest(): void
    {
        $a = $this->makeIncident(['no' => '2040_IN_001', 'title' => 'Payment gateway outage']);
        $b = $this->makeIncident(['no' => '2040_IN_002', 'title' => 'Core banking degradation']);
        $this->cachedDoc($a, 'postmortem.docx', "# Cached postmortem\n");

        $manifest = $this->service()->refresh();

        $disk = Storage::disk('local');
        $this->assertSame(2, $manifest['incidents']);
        $this->assertArrayHasKey('built_at', $manifest);
        $this->assertArrayHasKey('version', $manifest);
        $this->assertSame($manifest, Cache::get('incident_corpus_manifest'));

        $disk->assertExists('markdown/corpus/index.md');
        $disk->assertExists('markdown/corpus/2040_IN_001/incident.md');
        $disk->assertExists('markdown/corpus/2040_IN_001/documents/postmortem.docx.md');
        $disk->assertExists('markdown/corpus/2040_IN_002/incident.md');

        $this->assertStringContainsString('2040_IN_002', $disk->get('markdown/corpus/index.md'));
        $this->assertStringContainsString(
            'Payment gateway outage',
            $disk->get('markdown/corpus/2040_IN_001/incident.md')
        );
    }

    public function test_catalog_returns_lines_without_h1_and_null_before_build(): void
    {
        $this->assertNull($this->service()->catalog());

        $this->makeIncident(['no' => '2040_IN_003', 'title' => 'Fraud payout spike']);
        $this->service()->refresh();

        $catalog = $this->service()->catalog();
        $this->assertNotNull($catalog);
        $this->assertStringNotContainsString('# Incident corpus', $catalog);
        $this->assertStringContainsString('2040_IN_003', $catalog);
        $this->assertStringContainsString('Fraud payout spike', $catalog);
    }

    public function test_refresh_drops_deleted_incident_folders(): void
    {
        $a = $this->makeIncident(['no' => '2040_IN_004']);
        $b = $this->makeIncident(['no' => '2040_IN_005']);
        $this->service()->refresh();

        $a->delete();
        $this->service()->refresh();

        $disk = Storage::disk('local');
        $disk->assertMissing('markdown/corpus/2040_IN_004/incident.md');
        $disk->assertExists('markdown/corpus/2040_IN_005/incident.md');
    }

    public function test_scope_excludes_g_non_incident_and_issues(): void
    {
        $this->makeIncident(['no' => '2040_IN_006']);
        $this->makeIncident(['severity' => 'G', 'no' => '2040_G_001']);
        $this->makeIncident(['severity' => 'Non Incident', 'no' => '2040_NI_001']);
        $this->makeIncident(['classification' => 'Issue', 'no' => '2040_ISS_001']);

        $manifest = $this->service()->refresh();

        $this->assertSame(1, $manifest['incidents']);
        $index = Storage::disk('local')->get('markdown/corpus/index.md');
        $this->assertStringNotContainsString('2040_G_001', $index);
        $this->assertStringNotContainsString('2040_NI_001', $index);
        $this->assertStringNotContainsString('2040_ISS_001', $index);
    }

    public function test_is_stale_tracks_edits_version_and_count(): void
    {
        $incident = $this->makeIncident(['no' => '2040_IN_007']);
        $this->assertTrue($this->service()->isStale()); // never built

        // travel() between phases: staleness compares second-precision
        // timestamps, so phases must not share a second.
        $this->travel(2)->seconds();
        $this->service()->refresh();
        $this->assertFalse($this->service()->isStale());

        // Text edit — updated_at moves past built_at
        $this->travel(2)->seconds();
        $incident->update(['title' => 'Edited title']);
        $this->assertTrue($this->service()->isStale());

        $this->travel(2)->seconds();
        $this->service()->refresh();
        $this->assertFalse($this->service()->isStale());

        // Metrics/label changes bump dashboard_cache_version
        $this->travel(2)->seconds();
        Cache::increment('dashboard_cache_version');
        $this->assertTrue($this->service()->isStale());

        $this->travel(2)->seconds();
        $this->service()->refresh();
        $this->assertFalse($this->service()->isStale());

        // Delete — scoped count no longer matches the manifest
        $this->travel(2)->seconds();
        $incident->delete();
        $this->assertTrue($this->service()->isStale());
    }

    public function test_is_stale_after_document_update(): void
    {
        $incident = $this->makeIncident(['no' => '2040_IN_010']);
        $this->cachedDoc($incident, 'timeline.docx', "# Timeline\n");

        $this->travel(2)->seconds();
        $this->service()->refresh();
        $this->assertFalse($this->service()->isStale());

        // A document row newer than the build (edit or conversion write)
        // must mark the corpus stale — its incident row never moved.
        $this->travel(2)->seconds();
        \Illuminate\Support\Facades\DB::table('investigation_documents')
            ->where('incident_id', $incident->id)
            ->update(['description' => 'edited', 'updated_at' => now()]);

        $this->assertTrue($this->service()->isStale());
    }

    public function test_zero_incident_refresh_yields_empty_catalog(): void
    {
        $manifest = $this->service()->refresh();

        $this->assertSame(0, $manifest['incidents']);
        $this->assertFalse($this->service()->isStale());
        $this->assertSame('', $this->service()->catalog());
        Storage::disk('local')->assertExists('markdown/corpus/index.md');
    }

    public function test_command_skips_when_fresh_and_rebuilds_with_force(): void
    {
        $this->makeIncident(['no' => '2040_IN_008']);
        $this->travel(2)->seconds();
        $this->service()->refresh();

        $this->artisan('incidents:refresh-corpus')
            ->expectsOutput('Incident memory is fresh — skipped.')
            ->assertSuccessful();

        $builtAt = Cache::get('incident_corpus_manifest')['built_at'];
        $this->travel(1)->second(); // frozen clock would rebuild at the same stamp
        $this->artisan('incidents:refresh-corpus', ['--force' => true])
            ->expectsOutput('Incident memory rebuilt: 1 incidents.')
            ->assertSuccessful();
        $this->assertNotSame($builtAt, Cache::get('incident_corpus_manifest')['built_at']);
    }

    public function test_rebuild_button_on_agent_memory_page_writes_corpus(): void
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);
        $user = User::factory()->create();
        $user->givePermissionTo('manage api tokens');

        $this->makeIncident(['no' => '2040_IN_009', 'title' => 'Ledger mismatch']);

        Livewire::actingAs($user)
            ->test(ListAiAgentMemories::class)
            ->assertActionVisible('rebuild_incident_corpus')
            ->callAction('rebuild_incident_corpus')
            ->assertNotified('Incident memory rebuilt');

        Storage::disk('local')->assertExists('markdown/corpus/index.md');
        $this->assertStringContainsString('Ledger mismatch', Storage::disk('local')->get('markdown/corpus/index.md'));
    }
}
