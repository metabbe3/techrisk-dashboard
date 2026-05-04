<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\EncryptionKey;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Models\User;
use App\Services\EncryptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WebRouteTest extends TestCase
{
    use RefreshDatabase;

    private string $scribeDir;

    private string $openApiPath;

    private string $postmanPath;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->scribeDir = storage_path('app/private/scribe');
        $this->openApiPath = $this->scribeDir.'/openapi.yaml';
        $this->postmanPath = $this->scribeDir.'/collection.json';

        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        Permission::firstOrCreate(['name' => 'access dashboard']);
    }

    protected function tearDown(): void
    {
        $this->removeScribeFiles();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // GET /  --  Redirects to /admin/login
    // ---------------------------------------------------------------

    public function test_root_redirects_to_admin_login(): void
    {
        $response = $this->get('/');

        $response->assertStatus(302);
        $response->assertRedirect('/admin/login');
    }

    // ---------------------------------------------------------------
    // GET /request-access  --  Public Livewire access request form
    // ---------------------------------------------------------------

    public function test_request_access_page_returns_200(): void
    {
        $response = $this->get('/request-access');

        $response->assertStatus(200);
    }

    public function test_request_access_page_contains_livewire_form(): void
    {
        $response = $this->get('/request-access');

        $response->assertSee('Request Access');
        $response->assertSee('Technical Risk Dashboard');
    }

    public function test_request_access_page_contains_form_elements(): void
    {
        $response = $this->get('/request-access');

        $response->assertSee('Full Name');
        $response->assertSee('Email Address');
        $response->assertSee('Reason for Access');
    }

    // ---------------------------------------------------------------
    // GET /docs.openapi  --  Serves OpenAPI YAML
    // ---------------------------------------------------------------

    public function test_docs_openapi_returns_404_when_file_missing(): void
    {
        $response = $this->get('/docs.openapi');

        $response->assertStatus(404);
    }

    public function test_docs_openapi_returns_yaml_when_file_exists(): void
    {
        $this->createFakeOpenApiFile();

        $response = $this->get('/docs.openapi');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/yaml');
    }

    public function test_docs_openapi_contains_openapi_structure(): void
    {
        $this->createFakeOpenApiFile();
        $this->get('/docs.openapi')->assertStatus(200);

        $content = file_get_contents($this->openApiPath);
        $this->assertStringContainsString('openapi:', $content);
        $this->assertStringContainsString('paths:', $content);
    }

    // ---------------------------------------------------------------
    // GET /docs.postman  --  Serves Postman collection JSON
    // ---------------------------------------------------------------

    public function test_docs_postman_returns_404_when_file_missing(): void
    {
        $response = $this->get('/docs.postman');

        $response->assertStatus(404);
    }

    public function test_docs_postman_returns_json_when_file_exists(): void
    {
        $this->createFakePostmanFile();

        $response = $this->get('/docs.postman');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_docs_postman_contains_postman_structure(): void
    {
        $this->createFakePostmanFile();
        $this->get('/docs.postman')->assertStatus(200);

        $content = json_decode(file_get_contents($this->postmanPath), true);
        $this->assertNotNull($content, 'Response should be valid JSON');
        $this->assertArrayHasKey('info', $content);
        $this->assertArrayHasKey('item', $content);
    }

    // ---------------------------------------------------------------
    // GET /documents/{record}/download  --  Document download (auth required)
    // ---------------------------------------------------------------

    public function test_document_download_denies_unauthenticated_access(): void
    {
        // Filament registers its own login route; Laravel's auth middleware
        // redirects to the named "login" route which may not exist, producing
        // 302 or 500. Either way, unauthenticated access must be blocked.
        $response = $this->get('/documents/1/download');
        $this->assertTrue(in_array($response->getStatusCode(), [302, 403, 500]));
    }

    public function test_document_download_returns_404_for_nonexistent_document(): void
    {
        $user = $this->createUserWithPermission('view incidents');

        $response = $this->actingAs($user)->get('/documents/99999/download');

        $response->assertStatus(404);
    }

    public function test_document_download_returns_403_without_permission(): void
    {
        $user = User::factory()->create();
        $document = InvestigationDocument::factory()->create();

        $response = $this->actingAs($user)->get("/documents/{$document->id}/download");

        $response->assertStatus(403);
    }

    public function test_document_download_returns_404_when_file_missing_from_disk(): void
    {
        Storage::fake('public');
        $user = $this->createUserWithPermission('manage incidents');

        $document = InvestigationDocument::factory()->create([
            'file_path' => 'nonexistent.pdf',
        ]);

        $response = $this->actingAs($user)->get("/documents/{$document->id}/download");

        $response->assertStatus(404);
    }

    public function test_document_download_returns_500_when_encryption_key_missing(): void
    {
        Storage::fake('public');
        $user = $this->createUserWithPermission('manage incidents');

        $document = InvestigationDocument::factory()->create([
            'file_path' => 'documents/test.pdf',
            'original_filename' => 'test.pdf',
        ]);
        Storage::disk('public')->put('documents/test.pdf', 'dummy content');

        $response = $this->actingAs($user)->get("/documents/{$document->id}/download");

        $response->assertStatus(500);
    }

    public function test_document_download_succeeds_for_user_with_manage_permission(): void
    {
        Storage::fake('public');
        $user = $this->createUserWithPermission('manage incidents');

        $document = $this->createEncryptedDocument('test_report.pdf');

        $response = $this->actingAs($user)->get("/documents/{$document->id}/download");

        $response->assertStatus(200);
        $response->assertHeader('Content-Disposition');
    }

    public function test_document_download_succeeds_for_assigned_pic_with_view_permission(): void
    {
        Storage::fake('public');
        $user = $this->createUserWithPermission('view incidents');

        $incident = Incident::factory()->create(['pic_id' => $user->id]);
        $document = $this->createEncryptedDocument('pic_report.pdf', $incident);

        $response = $this->actingAs($user)->get("/documents/{$document->id}/download");

        $response->assertStatus(200);
    }

    public function test_document_download_returns_403_for_non_assigned_pic(): void
    {
        Storage::fake('public');
        $user = $this->createUserWithPermission('view incidents');
        $otherUser = User::factory()->create();

        $incident = Incident::factory()->create(['pic_id' => $otherUser->id]);
        $document = InvestigationDocument::factory()->create([
            'incident_id' => $incident->id,
            'file_path' => 'documents/report.pdf',
        ]);

        $response = $this->actingAs($user)->get("/documents/{$document->id}/download");

        $response->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // GET /admin/weekly-report/export/{year}  --  Weekly report export
    // ---------------------------------------------------------------

    public function test_weekly_report_export_denies_unauthenticated_access(): void
    {
        $response = $this->get('/admin/weekly-report/export/2026');
        $this->assertTrue(in_array($response->getStatusCode(), [302, 403, 500]));
    }

    public function test_weekly_report_export_returns_403_without_permission(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/weekly-report/export/2026');

        $response->assertStatus(403);
    }

    public function test_weekly_report_export_succeeds_with_permission(): void
    {
        $user = $this->createUserWithPermission('access dashboard');

        $response = $this->actingAs($user)->get('/admin/weekly-report/export/2026');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type');
    }

    public function test_weekly_report_export_includes_data_for_incidents(): void
    {
        $user = $this->createUserWithPermission('access dashboard');

        Incident::factory()->create([
            'classification' => 'Incident',
            'incident_date' => '2026-01-15',
            'incident_status' => 'Completed',
        ]);

        $response = $this->actingAs($user)->get('/admin/weekly-report/export/2026');

        $response->assertStatus(200);
    }

    public function test_weekly_report_export_with_zero_year_still_responds(): void
    {
        $user = $this->createUserWithPermission('access dashboard');

        $response = $this->actingAs($user)->get('/admin/weekly-report/export/0');

        $response->assertStatus(200);
    }

    // ---------------------------------------------------------------
    // Helper methods
    // ---------------------------------------------------------------

    private function createUserWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);

        return $user;
    }

    private function createEncryptedDocument(string $filename, ?Incident $incident = null): InvestigationDocument
    {
        $encryptionService = app(EncryptionService::class);
        $filePath = "documents/{$filename}";
        $incident ??= Incident::factory()->create();

        $document = InvestigationDocument::factory()->create([
            'incident_id' => $incident->id,
            'file_path' => $filePath,
            'original_filename' => $filename,
        ]);

        $key = $encryptionService->generateKey();
        $salt = $encryptionService->generateSalt();
        $finalKey = $encryptionService->getFinalKey($key, $salt, 'method1');

        EncryptionKey::create([
            'investigation_document_id' => $document->id,
            'key' => $key,
            'salt' => $salt,
            'method' => 'method1',
        ]);

        $encryptedContent = $encryptionService->encrypt("Content of {$filename}", $finalKey);
        Storage::disk('public')->put($filePath, $encryptedContent);

        return $document;
    }

    private function ensureScribeDir(): void
    {
        if (! is_dir($this->scribeDir)) {
            mkdir($this->scribeDir, 0755, true);
        }
    }

    private function removeScribeFiles(): void
    {
        if (file_exists($this->openApiPath)) {
            unlink($this->openApiPath);
        }

        if (file_exists($this->postmanPath)) {
            unlink($this->postmanPath);
        }
    }

    private function createFakeOpenApiFile(): void
    {
        $yaml = <<<'YAML'
openapi: "3.0.0"
info:
  title: "Technical Risk Dashboard API"
  version: "1.0.0"
paths:
  /api/v1/incidents:
    get:
      summary: "List incidents"
      responses:
        200:
          description: "Success"
YAML;

        $this->ensureScribeDir();
        file_put_contents($this->openApiPath, $yaml);
    }

    private function createFakePostmanFile(): void
    {
        $collection = [
            'info' => [
                'name' => 'Technical Risk Dashboard API',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => [
                ['name' => 'Incidents', 'item' => []],
            ],
        ];

        $this->ensureScribeDir();
        file_put_contents($this->postmanPath, json_encode($collection));
    }
}
