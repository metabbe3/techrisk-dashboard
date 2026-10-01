<?php

declare(strict_types=1);

namespace Tests\Feature\Markdown;

use App\Filament\Actions\ExportActionSchema;
use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Models\EncryptionKey;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Models\User;
use App\Services\EncryptionService;
use App\Services\Markdown\IncidentMarkdownZipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Export Markdown ZIP (owner 2026-10-01): one folder per incident in the
 * current view, full incident markdown + every convertible investigation
 * document as markdown, zipped for AI consumption. Incidents only,
 * METRIC_ELIGIBLE severity — the scope every other export shares.
 */
class IncidentMarkdownZipTest extends TestCase
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

    /** Real encrypted pdf/docx on the (faked) public disk, per WebRouteTest's helper. */
    private function encryptedDoc(Incident $incident, string $filename, string $plainContent): InvestigationDocument
    {
        $encryption = app(EncryptionService::class);
        $filePath = 'documents/'.$filename;

        $doc = InvestigationDocument::factory()->create([
            'incident_id' => $incident->id,
            'file_path' => $filePath,
            'original_filename' => $filename,
        ]);

        $key = $encryption->generateKey();
        $salt = $encryption->generateSalt();

        EncryptionKey::create([
            'investigation_document_id' => $doc->id,
            'key' => $key,
            'salt' => $salt,
            'method' => 'method1',
        ]);

        Storage::disk('public')->put(
            $filePath,
            $encryption->encrypt($plainContent, $encryption->getFinalKey($key, $salt, 'method1'))
        );

        return $doc;
    }

    private function buildZip(): array
    {
        $path = app(IncidentMarkdownZipService::class)
            ->build(ExportActionSchema::baseExportScope(Incident::query()));
        $this->assertNotNull($path, 'build() must return a zip path');
        $this->zips[] = $path;

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'zip must open');

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entries[] = $zip->getNameIndex($i);
            }

            return [$zip, $entries];
        } catch (\Throwable $e) {
            $zip->close();
            throw $e;
        }
    }

    public function test_zip_builds_one_folder_per_incident_with_index(): void
    {
        $a = $this->makeIncident(['no' => '2040_IN_001', 'title' => 'Payment gateway outage']);
        $b = $this->makeIncident(['no' => '2040_IN_002', 'title' => 'Core banking degradation']);

        [$zip, $entries] = $this->buildZip();

        $this->assertContains('index.md', $entries);
        $this->assertContains('2040_IN_001/incident.md', $entries);
        $this->assertContains('2040_IN_002/incident.md', $entries);

        $index = $zip->getFromName('index.md');
        $this->assertStringContainsString('2040_IN_001', $index);
        $this->assertStringContainsString('2040_IN_002', $index);
        $this->assertStringContainsString('Payment gateway outage', $index);

        $this->assertStringContainsString(
            'Core banking degradation',
            $zip->getFromName('2040_IN_002/incident.md')
        );
        $zip->close();
    }

    public function test_folder_names_are_sanitized(): void
    {
        $this->makeIncident(['no' => '2040/IN:P1 001']);

        [$zip, $entries] = $this->buildZip();

        $this->assertContains('2040_IN_P1_001/incident.md', $entries);
        $this->assertNotContains('2040/IN:P1 001/incident.md', $entries);
        $zip->close();
    }

    public function test_empty_query_returns_null(): void
    {
        $path = app(IncidentMarkdownZipService::class)
            ->build(ExportActionSchema::baseExportScope(Incident::query()));

        $this->assertNull($path);
    }

    public function test_cached_markdown_is_used_without_reconversion(): void
    {
        $incident = $this->makeIncident(['no' => '2040_IN_010']);
        // Cached md but NO encryption key row: an eagerly-converting build
        // would throw inside convert() and skip the doc — cached-first wins.
        $this->cachedDoc($incident, 'postmortem.docx', "# Cached postmortem\n\nStraight from cache.");

        [$zip, $entries] = $this->buildZip();

        $this->assertContains('2040_IN_010/documents/postmortem.docx.md', $entries);
        $this->assertStringContainsString(
            'Straight from cache',
            $zip->getFromName('2040_IN_010/documents/postmortem.docx.md')
        );
        $zip->close();
    }

    public function test_real_docx_is_converted_into_the_zip(): void
    {
        $word = new \PhpOffice\PhpWord\PhpWord;
        $word->addSection()->addText('Converted by the zip corpus builder.');
        $tmp = storage_path('app/private/temp/docx-'.Str::random(8).'.docx');
        @mkdir(dirname($tmp), 0777, true);
        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($tmp);
        $bytes = file_get_contents($tmp);
        unlink($tmp);

        $incident = $this->makeIncident(['no' => '2040_IN_011']);
        $this->encryptedDoc($incident, 'timeline.docx', $bytes);

        [$zip, $entries] = $this->buildZip();

        $this->assertContains('2040_IN_011/documents/timeline.docx.md', $entries);
        $this->assertStringContainsString(
            'Converted by the zip corpus builder',
            $zip->getFromName('2040_IN_011/documents/timeline.docx.md')
        );
        $zip->close();
    }

    public function test_unsupported_document_is_skipped_and_noted(): void
    {
        $incident = $this->makeIncident(['no' => '2040_IN_012']);
        InvestigationDocument::factory()->create([
            'incident_id' => $incident->id,
            'file_path' => 'documents/scan.png',
            'original_filename' => 'scan.png',
        ]);

        [$zip, $entries] = $this->buildZip();

        $this->assertNotContains('2040_IN_012/documents/scan.png.md', $entries);
        $md = $zip->getFromName('2040_IN_012/incident.md');
        $this->assertStringContainsString('Document conversion notes', $md);
        $this->assertStringContainsString('`scan.png` — skipped (unsupported type)', $md);
        $zip->close();
    }

    public function test_failed_conversion_is_skipped_without_killing_batch(): void
    {
        $incident = $this->makeIncident(['no' => '2040_IN_013']);
        // Keyless docx: decrypt throws inside convert() → noted, not fatal.
        InvestigationDocument::factory()->create([
            'incident_id' => $incident->id,
            'file_path' => 'documents/broken.docx',
            'original_filename' => 'broken.docx',
        ]);
        $this->cachedDoc($incident, 'fine.pdf.md', 'fine content');

        [$zip, $entries] = $this->buildZip();

        $this->assertContains('2040_IN_013/incident.md', $entries);
        $md = $zip->getFromName('2040_IN_013/incident.md');
        $this->assertStringContainsString('`broken.docx` — skipped (conversion failed)', $md);
        // The healthy sibling doc still made it.
        $this->assertContains('2040_IN_013/documents/fine.pdf.md.md', $entries);
        $zip->close();
    }

    public function test_duplicate_sanitized_names_are_deduped(): void
    {
        $incident = $this->makeIncident(['no' => '2040_IN_014']);
        $this->cachedDoc($incident, 'report one.pdf', 'first');
        $this->cachedDoc($incident, 'report_one.pdf', 'second');

        [$zip, $entries] = $this->buildZip();

        $this->assertContains('2040_IN_014/documents/report_one.pdf.md', $entries);
        $this->assertContains('2040_IN_014/documents/report_one_2.pdf.md', $entries);
        $zip->close();
    }

    public function test_base_export_scope_restricts_to_metric_eligible(): void
    {
        $this->makeIncident(['severity' => 'P1']);
        $this->makeIncident(['severity' => 'G']);
        $this->makeIncident(['severity' => 'Non Incident']);

        $this->assertSame(1, ExportActionSchema::baseExportScope(Incident::query())->count());

        // The applyFilters refactor must keep the same unconditional rule.
        $this->assertSame(
            1,
            ExportActionSchema::applyFilters(Incident::query(), [])->count()
        );
    }

    public function test_action_registered_on_incidents_list(): void
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo('view incidents');

        Livewire::actingAs($user)
            ->test(ListIncidents::class)
            ->assertActionVisible('export_markdown_zip');
    }
}
