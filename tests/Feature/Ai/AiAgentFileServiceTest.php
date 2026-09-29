<?php

namespace Tests\Feature\Ai;

use App\Models\AiAgent;
use App\Services\Ai\AiAgentFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiAgentFileServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_attaches_text_file_with_extracted_content(): void
    {
        $agent = AiAgent::create(['name' => 'Reader', 'instructions' => 'x', 'frequency' => 'Manual']);
        $file = UploadedFile::fake()->createWithContent('notes.txt', 'quarterly risk notes');

        $row = app(AiAgentFileService::class)->attach($agent, $file);

        $this->assertSame('notes.txt', $row->filename);
        $this->assertSame('quarterly risk notes', $row->extracted_text);
        $this->assertSame($agent->id, $row->agent_id);
        Storage::disk('local')->assertExists($row->path);
    }

    public function test_rejects_disallowed_extension(): void
    {
        $agent = AiAgent::create(['name' => 'Reader', 'instructions' => 'x', 'frequency' => 'Manual']);
        $file = UploadedFile::fake()->create('payload.exe', 100, 'application/x-msdownload');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not allowed');

        app(AiAgentFileService::class)->attach($agent, $file);
    }

    public function test_attach_many_collects_errors_without_aborting(): void
    {
        $agent = AiAgent::create(['name' => 'Reader', 'instructions' => 'x', 'frequency' => 'Manual']);
        $good = UploadedFile::fake()->createWithContent('ok.txt', 'fine');
        $bad = UploadedFile::fake()->create('bad.exe', 100, 'application/x-msdownload');

        $result = app(AiAgentFileService::class)->attachMany($agent, [$good, $bad]);

        $this->assertCount(1, $result['attached']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('bad.exe', $result['errors'][0]);
    }
}
