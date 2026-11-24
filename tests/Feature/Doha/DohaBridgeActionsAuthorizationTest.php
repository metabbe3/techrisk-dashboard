<?php

declare(strict_types=1);

namespace Tests\Feature\Doha;

use App\Models\DohaTicket;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Review F2 probe (resolved): Filament DOES enforce the visible() gate
 * server-side — isDisabled() → isHidden() → isHiddenInGroup() evaluates the
 * visible() closure (which carries canManage()) during mountAction/
 * callMountedAction, the exact methods a forged direct Livewire call hits.
 * These tests drive the RAW mountAction/callMountedAction path (no test-macro
 * visibility guard) and pin that a view-only user cannot mutate the link.
 */
class DohaBridgeActionsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view doha tickets']);
        Permission::firstOrCreate(['name' => 'access dashboard']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
        Permission::firstOrCreate(['name' => 'view incidents']);
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['access dashboard', 'view doha tickets', 'view incidents']);

        return $user;
    }

    public function test_a_forged_direct_link_call_cannot_link_an_incident(): void
    {
        $ticket = DohaTicket::factory()->unlinked()->create();
        $incident = Incident::factory()->create();

        $component = Livewire::actingAs($this->viewer())
            ->test(\App\Filament\Resources\DohaTicketResource\Pages\ViewDohaTicket::class, ['record' => $ticket->id]);

        // The raw production path: mount the action, then call it — bypassing
        // the testing macro's own visibility assertion.
        $component->call('mountAction', 'linkIncident')->call('callMountedAction', arguments: ['incident_id' => $incident->id]);

        $this->assertNull($ticket->fresh()->incident_id,
            'a view-only user must not be able to link an incident through a direct Livewire call');
    }

    public function test_a_forged_direct_unlink_call_cannot_unlink_an_incident(): void
    {
        $incident = Incident::factory()->create();
        $ticket = DohaTicket::factory()->linked($incident)->create();

        Livewire::actingAs($this->viewer())
            ->test(\App\Filament\Resources\DohaTicketResource\Pages\ViewDohaTicket::class, ['record' => $ticket->id])
            ->call('mountAction', 'unlinkIncident')
            ->call('callMountedAction');

        $this->assertSame($incident->id, $ticket->fresh()->incident_id,
            'a view-only user must not be able to unlink an incident through a direct Livewire call');
    }
}
