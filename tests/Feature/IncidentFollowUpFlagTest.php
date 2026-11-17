<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Models\Incident;
use App\Models\StatusUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Owner rule (2026-11-09): the incident list nudges PICs to keep statuses
 * fresh. No status updates at all -> "Need update" immediately;
 * last update > 6 days stale and unsettled (not Resolved/Recovered/Closed)
 * -> "Needs follow-up"; Completed never flagged. Pure list flag — no
 * notifications.
 */
class IncidentFollowUpFlagTest extends TestCase
{
    use RefreshDatabase;

    private function renderTable(): string
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo('view incidents');

        return (string) Livewire::actingAs($user)
            ->test(ListIncidents::class)
            ->html();
    }

    private function seedIncident(array $attributes = []): Incident
    {
        // Recent incident_date: the default quick_period filter scopes the
        // table to the current period and would hide old rows entirely.
        return Incident::factory()->create(array_merge([
            'classification' => 'Incident',
            'incident_date' => now()->startOfDay()->addHours(10),
        ], $attributes))->fresh();
    }

    public function test_flags_incident_with_stale_unsettled_last_update(): void
    {
        $incident = $this->seedIncident(['incident_status' => 'In progress']);
        StatusUpdate::create([
            'incident_id' => $incident->id,
            'status' => 'Investigation',
            'notes' => 'still digging',
            'update_date' => now()->subDays(7),
        ]);

        $this->assertMatchesRegularExpression('/>\s*Needs follow-up\s*</', $this->renderTable());
    }

    public function test_does_not_flag_incident_updated_recently(): void
    {
        $incident = $this->seedIncident(['incident_status' => 'Open']);
        StatusUpdate::create([
            'incident_id' => $incident->id,
            'status' => 'Investigation',
            'notes' => 'fresh',
            'update_date' => now()->subDays(5),
        ]);

        $this->assertDoesNotMatchRegularExpression('/>\s*Needs follow-up\s*</', $this->renderTable());
    }

    public function test_does_not_flag_incident_whose_last_update_is_settled(): void
    {
        $incident = $this->seedIncident(['incident_status' => 'In progress']);
        StatusUpdate::create([
            'incident_id' => $incident->id,
            'status' => 'Resolved',
            'notes' => 'resolved on the ground',
            'update_date' => now()->subDays(10),
        ]);

        $this->assertDoesNotMatchRegularExpression('/>\s*Needs follow-up\s*</', $this->renderTable());
    }

    public function test_does_not_flag_completed_incident(): void
    {
        $incident = $this->seedIncident([
            'incident_status' => 'Completed',
            // Observer closure gate (PROJ-11): completion requires
            // stop_bleeding_at + an RCA ('N/A' is the deliberate override)
            'stop_bleeding_at' => now()->startOfDay()->addHours(12),
            'root_cause' => 'N/A',
        ]);
        StatusUpdate::create([
            'incident_id' => $incident->id,
            'status' => 'Investigation',
            'notes' => 'stale but completed anyway',
            'update_date' => now()->subDays(30),
        ]);

        $this->assertDoesNotMatchRegularExpression('/>\s*Needs follow-up\s*</', $this->renderTable());
    }

    public function test_flags_never_updated_incident_with_need_update(): void
    {
        // Empty status updates flag immediately — no 6-day wait applies.
        $this->seedIncident([
            'incident_status' => 'Open',
            'created_at' => now(),
        ]);

        $this->assertMatchesRegularExpression('/>\s*Need update\s*</', $this->renderTable());
    }
}
