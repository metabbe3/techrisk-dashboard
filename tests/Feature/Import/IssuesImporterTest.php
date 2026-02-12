<?php

namespace Tests\Feature\Import;

use App\Filament\Importers\IssuesImporter;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuesImporterTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Import $import;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        IncidentType::factory()->create();

        $this->import = Import::create([
            'user_id' => $this->user->id,
            'file_name' => 'test.csv',
            'file_path' => 'test.csv',
            'importer' => IssuesImporter::class,
            'total_rows' => 1,
        ]);
    }

    public function test_creates_new_issue_when_no_duplicate_exists(): void
    {
        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'New Test Issue']);

        $record = $importer->resolveRecord();

        $this->assertInstanceOf(Incident::class, $record);
        $this->assertFalse($record->exists);
    }

    public function test_skips_duplicate_with_exact_title(): void
    {
        Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
            'no' => '20250101_IS_001',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'Login Error']);

        $this->expectException(RowImportFailedException::class);
        $this->expectExceptionMessage("Skipped: Issue 'Login Error' already exists as 'Login Error'");

        $importer->resolveRecord();
    }

    public function test_skips_duplicate_with_trailing_space(): void
    {
        Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
            'no' => '20250101_IS_001',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'Login Error ']);

        $this->expectException(RowImportFailedException::class);
        $this->expectExceptionMessage("Skipped: Issue 'Login Error ' already exists as 'Login Error'");

        $importer->resolveRecord();
    }

    public function test_skips_duplicate_with_leading_space(): void
    {
        Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
            'no' => '20250101_IS_001',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => '  Login Error']);

        $this->expectException(RowImportFailedException::class);

        $importer->resolveRecord();
    }

    public function test_skips_duplicate_with_case_difference(): void
    {
        Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
            'no' => '20250101_IS_001',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'LOGIN ERROR']);

        $this->expectException(RowImportFailedException::class);

        $importer->resolveRecord();
    }

    public function test_skips_duplicate_with_mixed_case(): void
    {
        Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
            'no' => '20250101_IS_001',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'LoGiN eRrOr']);

        $this->expectException(RowImportFailedException::class);

        $importer->resolveRecord();
    }

    public function test_skips_duplicate_with_multiple_spaces(): void
    {
        Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
            'no' => '20250101_IS_001',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'Login    Error']);

        $this->expectException(RowImportFailedException::class);

        $importer->resolveRecord();
    }

    public function test_skips_duplicate_with_notion_prefix(): void
    {
        Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
            'no' => '20250101_IS_001',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'Summary of Incident - Login Error']);

        $this->expectException(RowImportFailedException::class);

        $importer->resolveRecord();
    }

    public function test_creates_new_issue_for_different_title(): void
    {
        Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'Database Error']);

        $record = $importer->resolveRecord();

        $this->assertInstanceOf(Incident::class, $record);
        $this->assertFalse($record->exists);
    }

    public function test_only_matches_issues_not_incidents(): void
    {
        Incident::factory()->create([
            'classification' => 'Incident', // Not Issue
            'title' => 'Login Error',
            'no' => '20250101_IN_001',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'Login Error']);

        // Should NOT throw exception because classification is 'Incident', not 'Issue'
        $record = $importer->resolveRecord();

        $this->assertInstanceOf(Incident::class, $record);
        $this->assertFalse($record->exists);
    }

    public function test_combined_differences_are_still_detected(): void
    {
        Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
            'no' => '20250101_IS_001',
        ]);

        // All combined: prefix + spaces + case
        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'SUMMARY OF INCIDENT -  LOGIN   ERROR  ']);

        $this->expectException(RowImportFailedException::class);

        $importer->resolveRecord();
    }

    public function test_skipped_duplicate_message_includes_issue_id(): void
    {
        Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
            'no' => '20250101_IS_123',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'Login Error']);

        try {
            $importer->resolveRecord();
            $this->fail('Expected RowImportFailedException was not thrown');
        } catch (RowImportFailedException $e) {
            $this->assertStringContainsString('20250101_IS_123', $e->getMessage());
        }
    }

    public function test_does_not_update_existing_issue(): void
    {
        $existing = Incident::factory()->create([
            'classification' => 'Issue',
            'title' => 'Login Error',
            'severity' => 'P1',
            'no' => '20250101_IS_001',
        ]);

        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => 'Login Error']);

        try {
            $importer->resolveRecord();
        } catch (RowImportFailedException $e) {
            // Expected
        }

        // Verify original data was NOT changed
        $this->assertDatabaseHas('incidents', [
            'id' => $existing->id,
            'title' => 'Login Error',
            'severity' => 'P1',
        ]);
    }

    public function test_handles_empty_name(): void
    {
        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => '']);

        $record = $importer->resolveRecord();

        $this->assertInstanceOf(Incident::class, $record);
        $this->assertFalse($record->exists);
    }

    public function test_handles_null_name(): void
    {
        $importer = new IssuesImporter($this->import, ['Name' => 'Name'], []);
        $importer->setData(['Name' => null]);

        $record = $importer->resolveRecord();

        $this->assertInstanceOf(Incident::class, $record);
        $this->assertFalse($record->exists);
    }
}
