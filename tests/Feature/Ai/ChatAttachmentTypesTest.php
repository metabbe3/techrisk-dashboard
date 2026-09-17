<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Upload accepts images plus pdf/docx/xlsx/txt/md/csv/json; .doc and files
 * whose content doesn't match their extension are rejected; text formats are
 * stored raw (no converter); xlsx converts to a capped markdown table.
 */
class ChatAttachmentTypesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'access ai chat']);
        $this->user->givePermissionTo('access ai chat');

        Storage::fake('local');
    }

    private function upload(UploadedFile $file, int $status): object
    {
        return $this->actingAs($this->user)
            ->post('/admin/ai/chat/upload-attachment', ['file' => $file])
            ->assertStatus($status);
    }

    public function test_text_formats_are_stored_raw_as_documents(): void
    {
        $cases = [
            'notes.txt' => 'plain incident notes',
            'report.md' => '# Root Cause',
            'export.csv' => "sev,count\nP1,2",
            'data.json' => '{"sev":"P1"}',
        ];

        foreach ($cases as $filename => $content) {
            $res = $this->upload(UploadedFile::fake()->createWithContent($filename, $content), 200);

            $id = $res->json('data.attachment.id');
            $this->assertSame('document', $res->json('data.attachment.type'), "{$filename} should be a document");
            $this->assertTrue(
                Storage::disk('local')->exists("chat-attachments/{$id}.md"),
                "{$filename} should have a raw sidecar"
            );
            $this->assertSame($content, Storage::disk('local')->get("chat-attachments/{$id}.md"), "{$filename} sidecar should hold the raw content");
        }
    }

    public function test_xlsx_converts_to_a_capped_markdown_table(): void
    {
        config(['ai.attachments.xlsx_max_rows' => 2]);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Metrics');
        $sheet->fromArray([['Metric', 'Value'], ['MTTR', '42'], ['MTBF', '7'], ['Count', '9'], ['Extra', '1']], null, 'A1');
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        SpreadsheetIOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmp);

        $res = $this->upload(new UploadedFile($tmp, 'metrics.xlsx', null, null, true), 200);

        $id = $res->json('data.attachment.id');
        $markdown = Storage::disk('local')->get("chat-attachments/{$id}.md");

        $this->assertStringContainsString('### Sheet: Metrics', $markdown);
        $this->assertStringContainsString('| Metric | Value |', $markdown);
        $this->assertStringContainsString('| MTTR | 42 |', $markdown);
        // Header + 2 data rows shown of 5 total rows.
        $this->assertStringContainsString('showing first 3 of 5 rows', $markdown);
    }

    public function test_legacy_doc_extension_is_rejected(): void
    {
        $res = $this->upload(UploadedFile::fake()->create('legacy.doc', 1, 'application/msword'), 422);

        $this->assertStringContainsString('.doc files are not supported', $res->json('message'));
    }

    public function test_text_renamed_to_pdf_is_rejected(): void
    {
        // A real temp file, not UploadedFile::fake() — fakes report the
        // extension-guessed mime, which would defeat the content sniff.
        $tmp = tempnam(sys_get_temp_dir(), 'fakepdf');
        file_put_contents($tmp, 'this is just plain text');

        $res = $this->upload(new UploadedFile($tmp, 'fake.pdf', null, null, true), 422);

        $this->assertStringContainsString('does not match', $res->json('message'));
    }

    public function test_oversize_image_is_rejected(): void
    {
        // Valid PNG magic padded past the 5MB image cap — finfo still reports
        // image/png, so the rejection must come from the size check.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $tmp = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($tmp, $png.str_repeat("\0", 6 * 1024 * 1024));

        $res = $this->upload(new UploadedFile($tmp, 'big.png', null, null, true), 422);

        $this->assertStringContainsString('File too large', $res->json('message'));
    }
}
