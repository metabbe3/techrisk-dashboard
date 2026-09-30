<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\IncidentTableExport;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule (2026-09-30): export MTBF gap sequences are computed over
 * METRIC_ELIGIBLE rows only — a G row between two eligible incidents
 * must not count as a gap event (surface drift found in review).
 */
class IncidentTableExportMtbfTest extends TestCase
{
    use RefreshDatabase;

    public function test_mtbf_sequence_ignores_non_metric_severity_rows(): void
    {
        // Year 2027: untouched by other tests (per-year static cache).
        $p1 = Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2027-01-10 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'G', 'incident_date' => '2027-01-20 10:00']);
        $p2 = Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2027-01-30 10:00']);

        $export = new IncidentTableExport(collect([$p1, $p2]), [], ['MTBF (days)'], ['mtbf']);

        // Without the filter the G row splits the gap: P2 would read 10
        // (gap from Jan 20). Eligible-only sequence: Jan 10 -> Jan 30 = 20.
        $this->assertSame(20, $export->map($p2)[0]);
    }
}
