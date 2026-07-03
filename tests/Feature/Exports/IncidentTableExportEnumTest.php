<?php

namespace Tests\Feature\Exports;

use App\Enums\Severity;
use App\Exports\IncidentTableExport;
use App\Exports\Sheets\SingleIncidentSheetExport;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards against the EnumCast regression: severity/status/classification return
 * backed-enum instances, which PhpSpreadsheet cannot stringify into a cell.
 * The export map() must coerce them to their scalar value.
 */
class IncidentTableExportEnumTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_incident_sheet_coerces_severity_enum_to_string(): void
    {
        $incident = Incident::factory()->create(['severity' => 'P1']);

        $export = new SingleIncidentSheetExport(
            Incident::query(),
            'All Cases',
            ['Severity'],
            ['severity'],
        );

        $row = $export->map($incident);

        $this->assertIsString($row[0]);
        $this->assertSame('P1', $row[0]);
        $this->assertNotInstanceOf(Severity::class, $row[0]);
    }

    public function test_incident_table_export_coerces_severity_enum_to_string(): void
    {
        $incident = Incident::factory()->create(['severity' => 'Non Incident']);

        $export = new IncidentTableExport(
            collect([$incident]),
            [],
            ['Severity'],
            ['severity'],
        );

        $row = $export->map($incident);

        $this->assertIsString($row[0]);
        $this->assertSame('Non Incident', $row[0]);
    }
}
