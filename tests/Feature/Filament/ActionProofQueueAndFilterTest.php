<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\IncidentClassification;
use App\Filament\Resources\ActionImprovementResource\Pages\ListActionImprovements;
use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Models\ActionImprovement;
use App\Models\ActionImprovementEvidence;
use App\Models\Incident;
use App\Models\InvestigationFormRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ActionProofQueueAndFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_with_pending_proof_matches_has_pending_proof(): void
    {
        $incident = Incident::factory()->create(['classification' => IncidentClassification::Incident->value]);
        $submitter = User::factory()->create();

        $withPending = $incident->actionImprovements()->create([
            'title' => 'Pending proof item',
            'detail' => 'Detail',
            'pic_email' => [$submitter->email],
            'status' => ActionImprovement::STATUS_PENDING,
        ]);
        ActionImprovementEvidence::create([
            'action_improvement_id' => $withPending->id,
            'link' => 'https://example.com/proof',
            'submitted_by' => $submitter->id,
            'approved_at' => null,
        ]);

        $alreadyApproved = $incident->actionImprovements()->create([
            'title' => 'Approved proof item',
            'detail' => 'Detail',
            'pic_email' => [$submitter->email],
            'status' => ActionImprovement::STATUS_PENDING,
        ]);
        ActionImprovementEvidence::create([
            'action_improvement_id' => $alreadyApproved->id,
            'link' => 'https://example.com/approved',
            'submitted_by' => $submitter->id,
            'approved_at' => now(),
        ]);

        $noProof = $incident->actionImprovements()->create([
            'title' => 'No proof item',
            'detail' => 'Detail',
            'pic_email' => [$submitter->email],
            'status' => ActionImprovement::STATUS_PENDING,
        ]);

        $scopedIds = ActionImprovement::query()->withPendingProof()->pluck('id')->all();

        $this->assertSame([$withPending->id], $scopedIds);
        $this->assertTrue($withPending->hasPendingProof());
        $this->assertFalse($alreadyApproved->hasPendingProof());
        $this->assertFalse($noProof->hasPendingProof());
    }

    public function test_list_action_improvements_pending_proof_tab_filters_correctly(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $incident = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'incident_date' => now(),
        ]);

        $awaiting = $incident->actionImprovements()->create([
            'title' => 'Awaiting approval',
            'detail' => 'Detail',
            'pic_email' => ['worker@dana.id'],
            'status' => ActionImprovement::STATUS_PENDING,
        ]);
        ActionImprovementEvidence::create([
            'action_improvement_id' => $awaiting->id,
            'link' => 'https://example.com/proof',
            'submitted_by' => $admin->id,
            'approved_at' => null,
        ]);

        $plain = $incident->actionImprovements()->create([
            'title' => 'Plain open item',
            'detail' => 'Detail',
            'pic_email' => ['worker@dana.id'],
            'status' => ActionImprovement::STATUS_PENDING,
        ]);

        Livewire::test(ListActionImprovements::class)
            ->set('activeTab', 'pending_proof')
            ->assertCanSeeTableRecords([$awaiting])
            ->assertCanNotSeeTableRecords([$plain]);
    }

    public function test_incident_list_investigation_form_status_filter(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $pendingIncident = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'incident_date' => now(),
        ]);
        InvestigationFormRequest::create([
            'incident_id' => $pendingIncident->id,
            'team' => 'Core',
            'expires_at' => now()->addDays(5),
            'status' => InvestigationFormRequest::STATUS_PENDING,
        ]);

        $submittedIncident = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'incident_date' => now(),
        ]);
        InvestigationFormRequest::create([
            'incident_id' => $submittedIncident->id,
            'team' => 'Core',
            'expires_at' => now()->addDays(5),
            'status' => InvestigationFormRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $noneIncident = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'incident_date' => now(),
        ]);

        Livewire::test(ListIncidents::class)
            ->filterTable('investigation_form_status', 'pending')
            ->assertCanSeeTableRecords([$pendingIncident])
            ->assertCanNotSeeTableRecords([$submittedIncident, $noneIncident])
            ->filterTable('investigation_form_status', 'none')
            ->assertCanSeeTableRecords([$noneIncident])
            ->assertCanNotSeeTableRecords([$pendingIncident, $submittedIncident]);
    }
}
