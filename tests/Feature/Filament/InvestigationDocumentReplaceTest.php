<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\InvestigationDocumentsRelationManager;
use App\Jobs\ConvertDocumentToMarkdown;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Replacing a document's file via the relation manager EditAction must
 * invalidate the stale conversion: markdown_path/status reset + a fresh
 * ConvertDocumentToMarkdown dispatched. Before the fix the row kept
 * status=completed with the OLD markdown_path, so the markdown ZIP (and the
 * corpus / AI summarize) served the previous file's conversion forever.
 */
class InvestigationDocumentReplaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_replacing_file_resets_conversion_and_dispatches_reconvert(): void
    {
        $user = User::factory()->create();
        $incident = Incident::factory()->create();
        $document = InvestigationDocument::factory()
            ->for($incident)
            ->create([
                'file_path' => 'investigation-forms/old.encrypted',
                'original_filename' => 'report-v1.pdf',
                'markdown_path' => 'markdown/documents/1.md',
                'markdown_conversion_status' => 'completed',
            ]);

        Queue::fake();
        Storage::fake('public');
        $v2 = UploadedFile::fake()->createWithContent('report-v2.pdf', 'not really a pdf');

        Livewire::actingAs($user)
            ->test(InvestigationDocumentsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->callTableAction('edit', $document, data: [
                'file_path' => $v2,
                'description' => 'replaced version',
            ])
            ->assertHasNoTableActionErrors();

        $document->refresh();

        $this->assertNotSame('markdown/documents/1.md', $document->markdown_path, 'stale markdown_path survived the replace');
        $this->assertSame('pending', $document->markdown_conversion_status, 'completed status survived the replace');
        $this->assertSame('report-v2.pdf', $document->original_filename);
        $pushed = Queue::pushed(ConvertDocumentToMarkdown::class);
        $this->assertCount(1, $pushed, 'reconversion job not dispatched');
        $this->assertTrue($pushed[0]->document->is($document));
    }

    public function test_edit_without_new_file_keeps_conversion(): void
    {
        $user = User::factory()->create();
        $incident = Incident::factory()->create();
        $document = InvestigationDocument::factory()
            ->for($incident)
            ->create([
                'file_path' => 'investigation-forms/old.encrypted',
                'original_filename' => 'report-v1.pdf',
                'markdown_path' => 'markdown/documents/2.md',
                'markdown_conversion_status' => 'completed',
            ]);

        Queue::fake();

        Livewire::actingAs($user)
            ->test(InvestigationDocumentsRelationManager::class, [
                'ownerRecord' => $incident,
                'pageClass' => EditIncident::class,
            ])
            ->callTableAction('edit', $document, data: [
                'description' => 'desc only edit',
            ])
            ->assertHasNoTableActionErrors();

        $document->refresh();

        $this->assertSame('markdown/documents/2.md', $document->markdown_path);
        $this->assertSame('completed', $document->markdown_conversion_status);
        Queue::assertNotPushed(ConvertDocumentToMarkdown::class);
    }
}
