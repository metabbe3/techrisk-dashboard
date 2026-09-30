<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\Severity;
use App\Exports\MultiSheetIncidentsExport;
use App\Exports\Sheets\IssuesMetricSheetExport;
use App\Filament\Actions\ExportActionSchema;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule (2026-09-30): the All Tabs export contains only severity
 * P1–P4 / X1–X4 — including the 3 fresh-query Issue sheets — and the
 * "Non Incident" tab sheet is gone (it would be structurally empty).
 */
class MultiSheetIncidentsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_sheets_exclude_non_metric_severities(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-10 10:00', 'mttr' => 180]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'G', 'incident_date' => '2026-01-11 10:00', 'mttr' => 60]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'Non Incident', 'incident_date' => '2026-01-12 10:00', 'mttr' => 30]);
        // Issues bypass the main query with fresh Incident:: queries.
        Incident::factory()->createQuietly(['classification' => 'Issue', 'severity' => 'X1', 'incident_date' => '2026-02-01 10:00', 'mttr' => 45, 'mtbf' => 5]);
        Incident::factory()->createQuietly(['classification' => 'Issue', 'severity' => 'G', 'incident_date' => '2026-02-02 10:00', 'mttr' => 15, 'mtbf' => 2]);

        // Mirrors ListIncidents: the export consumes applyFilters output.
        $query = ExportActionSchema::applyFilters(Incident::query(), []);
        $export = new MultiSheetIncidentsExport(
            $query,
            array_values(ExportActionSchema::columnOptions()),
            array_keys(ExportActionSchema::columnOptions())
        );

        $sheets = $export->sheets();
        $titles = array_map(fn ($sheet) => $sheet->title(), $sheets);

        // 14 sheets, Non Incident tab removed, P4 tab (eligible) kept.
        $this->assertCount(14, $sheets);
        $this->assertNotContains('Non Incident', $titles);
        $this->assertContains('P4 Incidents', $titles);

        foreach ($sheets as $sheet) {
            $rows = $sheet->query()->get();
            foreach ($rows as $row) {
                $sev = $row->severity instanceof \BackedEnum ? $row->severity->value : $row->severity;
                $this->assertContains(
                    $sev,
                    Severity::METRIC_ELIGIBLE,
                    "{$sheet->title()} sheet holds non-metric severity {$sev}"
                );
            }
        }

        // The fresh-query Issues sheets hold the eligible Issue only.
        $issuesSheets = array_values(array_filter(
            $sheets,
            fn ($sheet) => $sheet instanceof IssuesMetricSheetExport || $sheet->title() === 'All Issues'
        ));
        $this->assertCount(3, $issuesSheets);
        foreach ($issuesSheets as $sheet) {
            $this->assertSame(1, $sheet->query()->count(), "{$sheet->title()} excludes the G Issue");
        }
    }
}
