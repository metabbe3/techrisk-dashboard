<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Models\InvestigationFormRecipient;
use App\Models\InvestigationFormRequest;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class InvestigationFormApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenWith(array $endpoints, array $abilities = ['*'], ?User $user = null): string
    {
        $user ??= User::factory()->create();
        Permission::firstOrCreate(['name' => 'access api']);
        $user->givePermissionTo('access api');

        $token = $user->createToken('test', $abilities);
        PersonalAccessToken::find($token->accessToken->id)
            ->forceFill(['allowed_endpoints' => json_encode($endpoints)])
            ->save();

        return $token->plainTextToken;
    }

    public function test_unauthenticated_and_unpermitted_token_are_rejected(): void
    {
        $this->getJson('/api/v1/investigation-forms')->assertUnauthorized();

        $token = $this->tokenWith(['incidents']);
        $this->withToken($token)
            ->getJson('/api/v1/investigation-forms')
            ->assertForbidden();
    }

    public function test_default_lists_open_forms_and_filters_by_state_team_and_incident(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $token = $this->tokenWith(['investigation-forms'], ['read:pii'], $admin);

        $inc1 = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'incident_date' => now(),
        ]);
        $openReq = InvestigationFormRequest::create([
            'incident_id' => $inc1->id,
            'team' => 'Payments',
            'expires_at' => now()->addDays(4),
            'status' => InvestigationFormRequest::STATUS_PENDING,
            'draft_updated_at' => now(),
        ]);
        InvestigationFormRecipient::create([
            'request_id' => $openReq->id,
            'email' => 'a@dana.id',
            'token_hash' => hash('sha256', 'tok-1'),
            'last_opened_at' => now(),
            'reminder_count' => 2,
        ]);

        $inc2 = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'incident_date' => now(),
        ]);
        $expiredReq = InvestigationFormRequest::create([
            'incident_id' => $inc2->id,
            'team' => 'Infra',
            'expires_at' => now()->subDay(),
            'status' => InvestigationFormRequest::STATUS_PENDING,
        ]);

        $submittedReq = InvestigationFormRequest::create([
            'incident_id' => $inc2->id,
            'team' => 'Payments',
            'expires_at' => now()->addDays(4),
            'status' => InvestigationFormRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitter_name' => 'Budi',
            'submitter_email' => 'budi@dana.id',
            'answers' => [
                'action_items' => [
                    ['item' => 'Fix pool', 'due_date' => '', 'verification' => '', 'owner' => ''],
                ],
            ],
        ]);

        $this->withToken($token)
            ->getJson('/api/v1/investigation-forms')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $openReq->id)
            ->assertJsonPath('data.0.state', 'pending')
            ->assertJsonPath('data.0.has_draft', true)
            ->assertJsonPath('data.0.recipient_count', 1)
            ->assertJsonPath('data.0.opened_count', 1)
            ->assertJsonPath('data.0.reminder_count', 2);

        $this->withToken($token)
            ->getJson('/api/v1/investigation-forms?status=expired')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $expiredReq->id)
            ->assertJsonPath('data.0.state', 'expired');

        $this->withToken($token)
            ->getJson('/api/v1/investigation-forms?status=submitted&team=Pay')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $submittedReq->id)
            ->assertJsonPath('data.0.action_items_count', 1)
            ->assertJsonPath('data.0.submitter_email', 'budi@dana.id');

        $this->withToken($token)
            ->getJson('/api/v1/investigation-forms?status=bogus')
            ->assertUnprocessable();
    }

    public function test_pii_is_hidden_without_read_pii_ability(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $token = $this->tokenWith(['investigation-forms'], ['read:basic'], $admin);

        $incident = Incident::factory()->create([
            'classification' => IncidentClassification::Incident->value,
            'incident_date' => now(),
        ]);
        InvestigationFormRequest::create([
            'incident_id' => $incident->id,
            'team' => 'Core',
            'expires_at' => now()->addDays(2),
            'status' => InvestigationFormRequest::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitter_name' => 'Secret Person',
            'submitter_email' => 'secret@dana.id',
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/v1/investigation-forms?status=submitted')
            ->assertOk();

        $this->assertArrayNotHasKey('submitter_email', $response->json('data.0'));
        $this->assertArrayNotHasKey('submitter_name', $response->json('data.0'));
    }
}
