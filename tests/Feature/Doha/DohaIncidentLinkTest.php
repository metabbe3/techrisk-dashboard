<?php

declare(strict_types=1);

namespace Tests\Feature\Doha;

use App\Filament\Resources\DohaTicketResource\Pages\ViewDohaTicket;
use App\Filament\Resources\IncidentResource\Pages\CreateIncident;
use App\Models\Category;
use App\Models\DohaTicket;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DohaIncidentLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'view doha tickets']);
        Permission::firstOrCreate(['name' => 'access dashboard']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        Permission::firstOrCreate(['name' => 'manage incidents']);

        $this->user = User::factory()->create();
        // Mirrors a real admin: the admin role holds every permission, and the
        // incident create page's render pipeline also evaluates 'view incidents'.
        $this->user->givePermissionTo(['access dashboard', 'view doha tickets', 'manage incidents', 'view incidents']);

        Category::create(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'Tech Risk']);
    }

    private function dohaTicket(): DohaTicket
    {
        return DohaTicket::factory()->create([
            'ref' => 'INC-0042',
            'title' => 'Timeout to database',
            'bank' => 'BCA',
            'domain' => 'Channel',
            'team' => 'Tech Risk',
            'service_impact' => 'Payment channel down',
            'root_cause' => 'DB connection pool exhausted',
            'recovery_action' => 'Failover to replica',
            'follow_up_action' => 'Add connection-pool alert',
            'priority' => 'Critical',
            'status' => 'Ongoing',
            'potential_loss' => 1500000,
            'recovered' => 500000,
            'transaction_date' => '2026-11-20',
            'transaction_time' => '08:30',
        ]);
    }

    public function test_create_page_prefills_from_the_doha_ticket(): void
    {
        $ticket = $this->dohaTicket();

        $component = Livewire::withQueryParams(['doha' => $ticket->doha_id])
            ->actingAs($this->user)
            ->test(CreateIncident::class);

        $this->assertNotNull($component->instance(), 'instance null on initial render');

        $component->assertFormSet([
                'title' => 'Timeout to database',
                'summary' => "Imported from DOHA ticket INC-0042.\n\nPayment channel down",
                'root_cause' => 'DB connection pool exhausted',
                'remark' => 'Failover to replica',
                'improvements' => 'Add connection-pool alert',
                // The money fields' afterStateHydrated renders id-ID for
                // display; parse() turns them back into numbers on save.
                'potential_fund_loss' => '1.500.000,00',
                'recovered_fund' => '500.000,00',
                'severity' => 'P1',
                'classification' => 'Incident',
                'responsible_team' => ['Tech Risk'],
                'incident_date' => '2026-11-20 08:30:00',
            ]);
    }

    public function test_unmapped_or_free_text_values_do_not_poison_the_prefill(): void
    {
        $ticket = $this->dohaTicket();
        $ticket->update(['priority' => 'Urgent', 'team' => 'Unknown Team']);

        Livewire::withQueryParams(['doha' => $ticket->doha_id])
            ->actingAs($this->user)
            ->test(CreateIncident::class)
            ->assertFormSet([
                'severity' => null,
                'responsible_team' => [],
            ]);
    }

    public function test_create_links_the_ticket_to_the_new_incident(): void
    {
        $ticket = $this->dohaTicket();

        Livewire::withQueryParams(['doha' => $ticket->doha_id])
            ->actingAs($this->user)
            ->test(CreateIncident::class)
            ->fillForm([
                'incident_type' => 'Tech',
                'incident_source' => 'Internal',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $incident = Incident::where('title', 'Timeout to database')->first();
        $this->assertNotNull($incident);
        $this->assertSame($incident->id, $ticket->fresh()->incident_id);
    }

    public function test_abandoned_create_links_nothing(): void
    {
        $ticket = $this->dohaTicket();

        Livewire::withQueryParams(['doha' => $ticket->doha_id])
            ->actingAs($this->user)
            ->test(CreateIncident::class);

        $this->assertNull($ticket->fresh()->incident_id);
    }

    public function test_link_action_sets_the_incident(): void
    {
        $ticket = $this->dohaTicket();
        $incident = Incident::factory()->create();

        Livewire::actingAs($this->user)
            ->test(ViewDohaTicket::class, ['record' => $ticket->id])
            ->callAction('linkIncident', data: ['incident_id' => $incident->id]);

        $this->assertSame($incident->id, $ticket->fresh()->incident_id);
    }

    public function test_unlink_action_clears_the_incident(): void
    {
        $incident = Incident::factory()->create();
        $ticket = DohaTicket::factory()->linked($incident)->create();

        Livewire::actingAs($this->user)
            ->test(ViewDohaTicket::class, ['record' => $ticket->id])
            ->callAction('unlinkIncident');

        $this->assertNull($ticket->fresh()->incident_id);
    }

    public function test_incident_actions_hidden_without_manage_permission(): void
    {
        $ticket = $this->dohaTicket();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['access dashboard', 'view doha tickets']);

        Livewire::actingAs($viewer)
            ->test(ViewDohaTicket::class, ['record' => $ticket->id])
            ->assertActionHidden('createIncident')
            ->assertActionHidden('linkIncident')
            ->assertActionHidden('unlinkIncident');
    }
}
