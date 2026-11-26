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

    /**
     * Owner 2026-11-24 item 4: DOHA embeds TAB-separated tables in the
     * service impact narrative — only THOSE blocks become HTML tables;
     * prose with inline pipes stays prose (the 281-row live sweep: 5 tables,
     * 276 prose).
     */
    public function test_service_impact_tab_table_renders_as_html_table(): void
    {
        $ticket = DohaTicket::factory()->make([
            'service_impact' => "Total impacted:\ndate         transactions         amount\n2026-09-23\t70\t 18.388.677 \n2026-09-24\t14\t 1.916.500 \nTotal: 496 transactions | Rp 120.231.316",
        ]);

        $html = $ticket->serviceImpactToMarkdown();

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('>date<', $html);
        $this->assertStringContainsString('>transactions<', $html);
        $this->assertStringContainsString('>amount<', $html);
        $this->assertStringContainsString('2026-09-23', $html);
        $this->assertStringContainsString('18.388.677', $html);
        // Prose above and below the table stays markdown text, not table.
        $this->assertStringNotContainsString('Total impacted:', '<table');
        $this->assertStringContainsString('Total impacted:', $html);
        $this->assertStringContainsString('Total: 496 transactions | Rp 120.231.316', $html);
    }

    public function test_service_impact_pipe_prose_stays_prose(): void
    {
        // The dominant live shape (INC-1240, INC-0038, ...) — never a table.
        $ticket = DohaTicket::factory()->make([
            'service_impact' => 'Total: 26 Transactions | Amount: Rp 8.818.000',
        ]);

        $this->assertStringNotContainsString('<table', $ticket->serviceImpactToMarkdown());
    }

    public function test_service_impact_table_cells_are_escaped(): void
    {
        // 3 cells / 2 TABs — the live INC-1218 shape.
        $ticket = DohaTicket::factory()->make([
            'service_impact' => "date         transactions         note\n<script>alert(1)</script>\t70\t<script>alert(2)</script>\n2026-09-23\t70\tok",
        ]);

        $html = $ticket->serviceImpactToMarkdown();

        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_service_impact_without_a_table_header_renders_headerless_table(): void
    {
        // INC-1213's stray single-tab spacing must not become a table.
        $ticket = DohaTicket::factory()->make([
            'service_impact' => "plain text line\nanother plain line",
        ]);

        $this->assertStringNotContainsString('<table', $ticket->serviceImpactToMarkdown());
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
