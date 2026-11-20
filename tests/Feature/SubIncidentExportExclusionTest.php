<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IncidentClassification;
use App\Enums\Severity;
use App\Filament\Actions\ExportActionSchema as FilamentExportActionSchema;
use App\Models\Incident;
use App\Services\Markdown\IncidentMarkdownCorpusService;
use App\Services\Markdown\IncidentMarkdownZipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PROJ-022: exports exclude sub-incidents entirely (Excel presets AND the
 * markdown ZIP) while the AI corpus keeps them — the corpus is the
 * knowledge base, not an export surface.
 */
class SubIncidentExportExclusionTest extends TestCase
{
    use RefreshDatabase;

    /** @var string[] absolute zip paths built during a test */
    private array $zips = [];

    protected function tearDown(): void
    {
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
        parent::tearDown();
    }

    private function seedSuperAndSub(): array
    {
        $super = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);

        $sub = Incident::factory()->subOf($super)->create([
            'classification' => IncidentClassification::Incident->value,
            'severity' => Severity::P1->value,
        ]);

        return [$super, $sub];
    }

    public function test_export_baseline_excludes_subs(): void
    {
        $this->seedSuperAndSub();

        $this->assertSame(1, FilamentExportActionSchema::baselineQuery(Incident::query(), [])->count());
        $this->assertSame(1, FilamentExportActionSchema::baselineQuery(Incident::query(), ['f_fund_status' => ['Confirmed loss']])->count());
    }

    public function test_export_filters_exclude_subs(): void
    {
        $this->seedSuperAndSub();

        $this->assertSame(1, FilamentExportActionSchema::applyFilters(Incident::query(), [])->count());
    }

    public function test_markdown_zip_scope_excludes_subs(): void
    {
        [$super, $sub] = $this->seedSuperAndSub();

        // Mirrors the ZIP action's query expression on ListIncidents.
        $path = app(IncidentMarkdownZipService::class)
            ->build(FilamentExportActionSchema::baseExportScope(Incident::query())->withoutSubIncidents());
        $this->assertNotNull($path);
        $this->zips[] = $path;

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertContains($super->no.'/incident.md', $entries);
        $this->assertNotContains($sub->no.'/incident.md', $entries);
    }

    public function test_ai_corpus_still_includes_subs(): void
    {
        $this->seedSuperAndSub();

        // The asymmetry pin: the corpus is a knowledge base — subs stay.
        $this->assertSame(2, IncidentMarkdownCorpusService::scopedQuery()->count());
    }
}
