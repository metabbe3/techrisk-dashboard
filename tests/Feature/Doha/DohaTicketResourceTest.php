<?php

declare(strict_types=1);

namespace Tests\Feature\Doha;

use App\Filament\Resources\DohaTicketResource;
use App\Filament\Resources\DohaTicketResource\Pages\ListDohaTickets;
use App\Filament\Resources\DohaTicketResource\Pages\ViewDohaTicket;
use App\Models\DohaTicket;
use App\Models\DohaTicketChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DohaTicketResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view doha tickets']);
        Permission::firstOrCreate(['name' => 'access dashboard']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);
    }

    private function permittedUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['access dashboard', 'view doha tickets']);

        return $user;
    }

    public function test_can_view_any_gates_on_the_permission(): void
    {
        $this->actingAs($this->permittedUser());
        $this->assertTrue(DohaTicketResource::canViewAny());

        $stranger = User::factory()->create();
        $stranger->givePermissionTo(['access dashboard']);
        $this->actingAs($stranger);
        $this->assertFalse(DohaTicketResource::canViewAny());
    }

    /** The Spotlight palette evaluates this on guest pages — no user, no 500. */
    public function test_can_view_any_is_guest_safe(): void
    {
        $this->assertFalse(DohaTicketResource::canViewAny());
    }

    public function test_resource_is_read_only(): void
    {
        $ticket = DohaTicket::factory()->create();

        $this->assertFalse(DohaTicketResource::canCreate());
        $this->assertFalse(DohaTicketResource::canEdit($ticket));
        $this->assertFalse(DohaTicketResource::canDelete($ticket));
        $this->assertFalse(DohaTicketResource::canDeleteAny());
    }

    public function test_list_page_renders_for_permitted_users(): void
    {
        DohaTicket::factory()->create(['ref' => 'INC-0042']);

        $this->actingAs($this->permittedUser());
        config(['app.debug' => false]);

        $response = $this->get('/admin/doha-tickets');

        $response->assertOk();
        $response->assertSee('INC-0042');
    }

    public function test_list_page_denied_without_permission(): void
    {
        $stranger = User::factory()->create();
        $stranger->givePermissionTo(['access dashboard']);

        $this->actingAs($stranger);
        config(['app.debug' => false]);

        $this->get('/admin/doha-tickets')->assertForbidden();
    }

    public function test_view_page_renders_the_change_history(): void
    {
        $ticket = DohaTicket::factory()->create(['ref' => 'INC-0042']);
        DohaTicketChange::create([
            'doha_ticket_id' => $ticket->id,
            'field' => 'status',
            'old_value' => 'Ongoing',
            'new_value' => 'Closed',
        ]);

        Livewire::actingAs($this->permittedUser())
            ->test(ViewDohaTicket::class, ['record' => $ticket->id])
            ->assertSuccessful()
            ->assertSee('INC-0042')
            ->assertSee('Ongoing')
            ->assertSee('Closed');
    }

    /** Owner 2026-11-24: the list defaults to newest DOHA transaction first. */
    public function test_list_default_sorts_by_latest_transaction_date(): void
    {
        $older = DohaTicket::factory()->create([
            'ref' => 'INC-OLDER', 'transaction_date' => '2026-11-10',
        ]);
        $newer = DohaTicket::factory()->create([
            'ref' => 'INC-NEWER', 'transaction_date' => '2026-11-20',
        ]);

        Livewire::actingAs($this->permittedUser())
            ->test(ListDohaTickets::class)
            ->assertSeeInOrder(['INC-NEWER', 'INC-OLDER']);
    }

    /** Owner 2026-11-24: create-incident sits in the kebab right of View. */
    public function test_list_rows_offer_create_incident_for_admins_only(): void
    {
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $ticket = DohaTicket::factory()->unlinked()->create();
        $admin = $this->permittedUser();
        $admin->givePermissionTo('manage incidents');

        Livewire::actingAs($admin)
            ->test(ListDohaTickets::class)
            ->assertTableActionVisible('createIncident', $ticket);

        $viewer = $this->permittedUser();
        Livewire::actingAs($viewer)
            ->test(ListDohaTickets::class)
            ->assertTableActionHidden('createIncident', $ticket);
    }
}
