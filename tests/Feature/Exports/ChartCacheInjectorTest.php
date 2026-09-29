<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\Concerns\ChartCacheInjector;
use App\Exports\ExecutiveIncidentsExport;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * BUG-013 round 2: the injector's replace-branch backreference was
 * `</c:\3>` while group 3 already captures the "c:" prefix — so the
 * branch demanded literal `</c:c:numCache>`, never matched, and the
 * filled cache was APPENDED beside the writer's empty one. Two cache
 * elements inside one ref violates the OOXML schema (CT_NumRef/CT_StrRef
 * allow exactly one) — real Excel shows the "we found a problem… repair?"
 * prompt while PhpSpreadsheet/Numbers open the file fine.
 *
 * This runs the real download-path sequence: store → inject.
 */
class ChartCacheInjectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_injected_charts_carry_exactly_one_cache_per_ref(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-15 00:00:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2026-02-15 00:00:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'X1', 'incident_date' => '2026-03-15 00:00:00']);

        $fname = 'test-chart-injector.xlsx';
        Excel::store(new ExecutiveIncidentsExport(Incident::query()), $fname, 'local');
        $path = Storage::disk('local')->path($fname);
        ChartCacheInjector::inject($path);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path), 'failed to open written xlsx');
        try {
            $checked = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (! preg_match('#^xl/charts/chart\d+\.xml$#', $name)) {
                    continue;
                }
                $checked++;
                $xml = (string) $zip->getFromIndex($i);

                // Structural cardinality: exactly one cache element per ref.
                // A duplicate cache is the schema violation that makes real
                // Excel flag the workbook for repair.
                $this->assertSame(
                    substr_count($xml, '<c:numRef>'),
                    substr_count($xml, '<c:numCache>'),
                    "{$name}: numCache count must equal numRef count"
                );
                $this->assertSame(
                    substr_count($xml, '<c:strRef>'),
                    substr_count($xml, '<c:strCache>'),
                    "{$name}: strCache count must equal strRef count"
                );

                // BUG-013 invariant: caches hold values, not empty shells —
                // otherwise Numbers/QuickLook render blank charts.
                $this->assertGreaterThan(
                    0,
                    preg_match_all('#<c:pt idx="\d+"><c:v>#', $xml),
                    "{$name}: no cached values in chart"
                );

                // The regex rewrite must leave well-formed XML behind.
                $this->assertNotFalse(simplexml_load_string($xml), "{$name}: chart XML not well-formed");
            }
            $this->assertSame(4, $checked, 'executive export must ship 4 charts');
        } finally {
            $zip->close();
            @unlink($path);
        }
    }
}
