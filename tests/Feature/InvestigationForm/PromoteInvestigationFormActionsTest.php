<?php

declare(strict_types=1);

namespace Tests\Feature\InvestigationForm;

use App\Enums\IncidentClassification;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\InvestigationFormsRelationManager;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Models\User;
use App\Services\InvestigationForm\PromoteInvestigationFormActions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PromoteInvestigationFormActionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSubmittedRequest(Incident $incident, array $actionItems): array
    {
        $request = InvestigationFormRequest::create([
            'incident_id' => $incident->id,
            'team' => 'Payments',
            'expires_at' => now()->addDays(7),
            'status' => InvestigationFormRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'answers' => [
                'action_items' => $actionItems,
            ],
        ]);

        $recipient = InvestigationFormRecipient::create([
            'request_id' => $request->id,
            'email' => 'investigator@dana.id',
            'token_hash' => hash('sha256', 'test-token-'.$request->id),
        ]);

        return [$request, $recipient];
    }

    public function test_draft_rows_maps_dates_resolves_owners_and_flags_duplicates(): void
    {
        $ownerByName = User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@dana.id']);
        $ownerByEmail = User::factory()->create(['name' => 'Siti Aminah', 'email' => 'siti@dana.id']);
        $incident = Incident::factory()->create(['classification' => IncidentClassification::Incident->value]);

        $incident->actionImprovements()->create([
            'title' => 'Add circuit breaker',
            'detail' => 'Existing item',
            'pic_email' => ['budi@dana.id'],
            'priority' => 'high',
            'status' => ActionImprovement::STATUS_PENDING,
        ]);

        [$request] = $this->makeSubmittedRequest($incident, [
            [
                'item' => 'Add circuit breaker',
                'due_date' => '2026-12-01T17:00',
                'verification' => 'Load test passes',
                'owner' => 'Budi Santoso',
            ],
            [
                'item' => 'Add pool metric alert',
                'due_date' => 'TBD',
                'verification' => 'Alert fires in staging',
                'owner' => 'SITI@DANA.ID',
            ],
            [
                'item' => 'Update runbook',
                'due_date' => '',
                'verification' => '',
                'owner' => 'Unknown Squad',
            ],
            [
                'item' => '   ',
                'due_date' => '',
                'verification' => '',
                'owner' => '',
            ],
        ]);

        $rows = app(PromoteInvestigationFormActions::class)->draftRows($request);

        $this->assertCount(3, $rows);
        $this->assertSame('Add circuit breaker', $rows[0]['title']);
        $this->assertSame('2026-12-01', $rows[0]['due_date']);
        $this->assertSame([$ownerByName->email], $rows[0]['pic_email']);
        $this->assertTrue($rows[0]['already_exists']);

        $this->assertSame('Add pool metric alert', $rows[1]['title']);
        $this->assertNull($rows[1]['due_date']);
        $this->assertSame([$ownerByEmail->email], $rows[1]['pic_email']);
        $this->assertFalse($rows[1]['already_exists']);

        $this->assertSame([], $rows[2]['pic_email']);
        $this->assertStringContainsString('Form owner: Unknown Squad', $rows[2]['detail']);
    }

    public function test_import_creates_action_improvements_transactionally(): void
    {
        $incident = Incident::factory()->create(['classification' => IncidentClassification::Incident->value]);

        $created = app(PromoteInvestigationFormActions::class)->import($incident, [
            [
                'title' => 'Increase timeout ceiling',
                'detail' => 'Raise gateway timeout to 10s',
                'due_date' => '2026-12-15',
                'pic_email' => ['ops@dana.id'],
                'priority' => 'high',
                'status' => ActionImprovement::STATUS_PENDING,
            ],
            [
                'title' => 'Document failover steps',
                'detail' => 'Update Confluence SOP',
                'due_date' => null,
                'pic_email' => [],
                'priority' => 'low',
                'status' => ActionImprovement::STATUS_TBD,
            ],
        ]);

        $this->assertCount(2, $created);
        $this->assertDatabaseHas('action_improvements', [
            'incident_id' => $incident->id,
            'title' => 'Increase timeout ceiling',
            'priority' => 'high',
            'status' => ActionImprovement::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('action_improvements', [
            'incident_id' => $incident->id,
            'title' => 'Document failover steps',
            'due_date' => null,
            'status' => ActionImprovement::STATUS_TBD,
        ]);
    }

    public function test_relation_manager_import_actions_modal_creates_rows_for_manager_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $viewer = User::factory()->create();
        $viewer->assignRole('user');

        $incident = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'incident_date' => now(),
        ]);

        [$request, $recipient] = $this->makeSubmittedRequest($incident, [
            [
                'item' => 'Add retry jitter',
                'due_date' => '2026-12-10T09:00',
                'verification' => 'Unit test + canary',
                'owner' => 'ops@dana.id',
            ],
        ]);

        $this->actingAs($admin);
        Livewire::test(InvestigationFormsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => ViewIncident::class,
        ])
            ->assertTableActionVisible('import_actions', $recipient)
            ->callTableAction('import_actions', $recipient, data: [
                'items' => [
                    [
                        'title' => 'Add retry jitter',
                        'detail' => 'Unit test + canary',
                        'due_date' => '2026-12-10',
                        'pic_email' => ['ops@dana.id'],
                        'priority' => 'high',
                        'status' => ActionImprovement::STATUS_PENDING,
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('action_improvements', [
            'incident_id' => $incident->id,
            'title' => 'Add retry jitter',
            'priority' => 'high',
        ]);

        $this->actingAs($viewer);
        Livewire::test(InvestigationFormsRelationManager::class, [
            'ownerRecord' => $incident,
            'pageClass' => ViewIncident::class,
        ])
            ->assertTableActionHidden('import_actions', $recipient);
    }
}
