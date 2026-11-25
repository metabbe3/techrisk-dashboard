<?php

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource\Pages\EditIncident;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PIC Incident (2026-11-24): a second person-role pivot beside the existing
 * incident_pic (labelled "PIC Tech Risk"). Display-only — no notifications,
 * no access-scope change.
 */
class IncidentPicRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_pic_incident_pivot_persists_and_name_accessor_renders(): void
    {
        $incident = Incident::factory()->create();
        $picIncident = User::factory()->create(['name' => 'Ian Cident']);

        $incident->picIncident()->sync([$picIncident->id]);

        $this->assertDatabaseHas('incident_pic_incident', ['incident_id' => $incident->id, 'user_id' => $picIncident->id]);
        $this->assertSame('Ian Cident', $incident->pic_incident_names);
        $this->assertSame('Ian Cident', $incident->fresh()->pic_incident_names);
    }

    public function test_edit_form_saves_pic_incident(): void
    {
        foreach (['view incidents', 'manage incidents'] as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }
        $manager = User::factory()->create()->givePermissionTo('view incidents', 'manage incidents');
        $incident = Incident::factory()->create();
        $picIncident = User::factory()->create();

        Livewire::actingAs($manager)
            ->test(EditIncident::class, ['record' => $incident->getRouteKey()])
            ->fillForm([
                'picIncident' => [$picIncident->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$picIncident->id], $incident->fresh()->picIncident->pluck('id')->all());
        $this->assertEmpty($incident->fresh()->pics, 'PIC Incident must NOT write the main pics pivot');
    }
}
