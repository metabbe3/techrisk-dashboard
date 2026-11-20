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

    public function test_the_kanban_board_view_compiles_to_valid_php(): void
    {
        // Owner 2026-11-17: the Board view button 500'd with ParseError at
        // blade:284 — the @php(expr) ONE-LINERS (283/468) are swallowed by
        // Blade's storePhpBlocks pre-pass, which pairs them with the NEXT
        // @endphp in the file (311/439) and stores the whole span as one
        // raw php block — raw directives included — so the @if text landed
        // inside an open php tag. Fixed by the inline-block form the
        // file already uses elsewhere (@php ...; @endphp). The board still
        // cannot RENDER under SQLite (FIELD()) — but it must COMPILE anywhere.
        $compiled = (new \Illuminate\View\Compilers\BladeCompiler(app('files'), storage_path('framework/views')))
            ->compileString(file_get_contents(resource_path('views/livewire/incident-kanban-board.blade.php')));

        // No raw directive may survive compilation, and the one-liner's
        // malformed php-open-with-paren artifact must not either.
        $this->assertStringNotContainsString('@if(', $compiled);
        $this->assertStringNotContainsString('@php', $compiled); // also pins no surviving @endphp
        $this->assertStringNotContainsString('php(', $compiled);
    }

    public function test_update_status_action_no_longer_overwrites_incident_date(): void
    {
        $this->seedIncident();
        $incident = Incident::first();

        $incident->update([
            'incident_status' => 'Completed', 'root_cause' => 'Test RCA', 'stop_bleeding_at' => now(),
            'remark' => 'wrapped up',
        ]);

        $this->assertSame(
            '2026-03-05 10:00:00',
            $incident->fresh()->incident_date->format('Y-m-d H:i:s'),
            'Status change must never move the occurrence date'
        );
        $this->assertSame('Completed', $incident->fresh()->incident_status->value);
    }

    public function test_reporting_generate_with_pic_column_renders_pic_names(): void
    {
        // Multi-PIC cutover: the old 'pic.name'/'pic.email' options eager-loaded
        // the deleted pic() relation and 500'd. The PIC column is 'pic' —
        // joined names, no relation eager-load.
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view incidents']);
        $user = \App\Models\User::factory()->create();
        $user->assignRole('admin');
        $user->givePermissionTo('view incidents');

        $pic = \App\Models\User::factory()->create(['name' => 'Andi Pratama']);
        $incident = Incident::factory()->create([
            'severity' => 'P1',
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'incident_date' => '2026-03-05 10:00:00',
        ]);
        $incident->pics()->attach($pic->id);

        \Livewire\Livewire::actingAs($user)
            ->test(\App\Filament\Pages\Reporting::class)
            ->set('data.columns', ['pic'])
            ->call('generateReport')
            ->assertSuccessful()
            ->assertSee('Andi Pratama');
    }
}
