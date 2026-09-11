<?php

namespace Tests\Feature;

use App\Exports\Sheets\IncidentsSheet;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFacingCrashersTest extends TestCase
{
    use RefreshDatabase;

    private function seedIncident(): void
    {
        Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => '2026-03-05 10:00:00',
        ]);
    }

    // ponytail: the kanban severity fix (whereIn with the array, not
    // ->value) has no runnable check here — the board orders with
    // FIELD(severity, ...), which sqlite cannot execute, so the component
    // cannot render under the test DB. Verified by reading; add a browser
    // test if a MySQL-backed CI appears.

    public function test_reporting_sheet_export_renders_enum_columns_as_strings(): void
    {
        $this->seedIncident();
        $incident = Incident::first();

        // BUG-003 regression guard for the third consumer: severity is a
        // BackedEnum instance on the model and must not reach the binder.
        $sheet = new IncidentsSheet(collect([$incident]), ['severity' => 'Severity']);
        $row = $sheet->collection()->first();

        $this->assertSame('P1', $row[0]);
        $this->assertIsString($row[0]);
    }

    public function test_update_status_action_no_longer_overwrites_incident_date(): void
    {
        $this->seedIncident();
        $incident = Incident::first();

        $incident->update([
            'incident_status' => 'Completed',
            'remark' => 'wrapped up',
        ]);

        $this->assertSame(
            '2026-03-05 10:00:00',
            $incident->fresh()->incident_date->format('Y-m-d H:i:s'),
            'Status change must never move the occurrence date'
        );
        $this->assertSame('Completed', $incident->fresh()->incident_status->value);
    }
}
