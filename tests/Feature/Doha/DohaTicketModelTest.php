<?php

declare(strict_types=1);

namespace Tests\Feature\Doha;

use App\Models\DohaTicket;
use App\Models\DohaTicketChange;
use App\Models\Incident;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DohaTicketModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DOHA timestamps arrive as naive UTC strings ("2026-11-24 01:30:00") and
     * MUST round-trip byte-identical — they feed the sync watermark. A
     * datetime cast would parse them as app-timezone (WIB) and silently
     * shift them 7 hours.
     */
    public function test_doha_timestamps_stay_raw_strings(): void
    {
        $ticket = DohaTicket::factory()->create([
            'doha_created_at' => '2026-11-24 01:30:00',
            'doha_updated_at' => '2026-11-24 01:30:00',
        ]);

        $fresh = $ticket->fresh();

        $this->assertSame('2026-11-24 01:30:00', $fresh->getRawOriginal('doha_updated_at'));
        $this->assertSame('2026-11-24 01:30:00', $fresh->doha_updated_at);
        $this->assertSame('2026-11-24 01:30:00', $fresh->doha_created_at);
    }

    /** UTC string displayed in app timezone: 01:30 UTC = 08:30 WIB. */
    public function test_doha_timestamp_accessors_convert_utc_to_app_timezone(): void
    {
        $ticket = DohaTicket::factory()->create([
            'doha_created_at' => '2026-11-24 01:30:00',
            'doha_updated_at' => '2026-11-24 01:30:00',
        ]);

        $this->assertSame('2026-11-24 08:30', $ticket->doha_updated_at_wib->format('Y-m-d H:i'));
        $this->assertSame('2026-11-24 08:30', $ticket->doha_created_at_wib->format('Y-m-d H:i'));
    }

    public function test_timestamp_accessors_are_null_safe(): void
    {
        $ticket = DohaTicket::factory()->create([
            'doha_created_at' => null,
            'doha_updated_at' => null,
        ]);

        $this->assertNull($ticket->doha_updated_at_wib);
        $this->assertNull($ticket->doha_created_at_wib);
    }

    /**
     * The link is incidental to the mirror: deleting the Incident must keep
     * the DOHA ticket (nullOnDelete), and deleting the ticket must drop its
     * change history (cascade).
     */
    public function test_incident_link_nulls_on_incident_delete_and_changes_cascade(): void
    {
        $incident = Incident::factory()->create();
        $ticket = DohaTicket::factory()->linked($incident)->create();
        DohaTicketChange::create([
            'doha_ticket_id' => $ticket->id,
            'field' => 'status',
            'old_value' => 'Ongoing',
            'new_value' => 'Closed',
        ]);

        $incident->delete();
        $this->assertNull($ticket->fresh()->incident_id);
        $this->assertDatabaseHas('doha_tickets', ['id' => $ticket->id]);

        $ticket->delete();
        $this->assertDatabaseMissing('doha_ticket_changes', ['doha_ticket_id' => $ticket->id]);
    }

    public function test_changes_relation_orders_latest_first(): void
    {
        $ticket = DohaTicket::factory()->create();
        $older = DohaTicketChange::create([
            'doha_ticket_id' => $ticket->id,
            'field' => 'status',
            'old_value' => 'Ongoing',
            'new_value' => 'On Progress',
        ]);
        $newer = DohaTicketChange::create([
            'doha_ticket_id' => $ticket->id,
            'field' => 'potential_loss',
            'old_value' => '1000000',
            'new_value' => '2000000',
        ]);

        $this->assertTrue($ticket->changes->first()->is($newer));
        $this->assertTrue($ticket->changes->last()->is($older));
    }

    public function test_view_doha_tickets_permission_comes_from_the_seeder(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $permission = Permission::where('name', 'view doha tickets')->first();
        $this->assertNotNull($permission, 'view doha tickets permission must exist');

        $admin = Role::where('name', 'admin')->first();
        $this->assertTrue($admin->hasPermissionTo('view doha tickets'));

        // NOT granted to the other roles (owner decision 2026-11-23).
        $this->assertFalse(Role::where('name', 'user')->first()->hasPermissionTo('view doha tickets'));
        $this->assertFalse(Role::where('name', 'team-viewer')->first()->hasPermissionTo('view doha tickets'));
    }
}
