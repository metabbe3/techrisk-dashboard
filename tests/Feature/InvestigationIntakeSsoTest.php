<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActionImprovement;
use App\Models\ActionImprovementEvidence;
use App\Models\Incident;
use App\Models\InvestigationDocument;
use App\Models\InvestigationFormRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The intake is SSO-only: guests go straight to Microsoft (the
 * redirectGuestsTo branch) and come back to the exact link via url.intended.
 * Role-less SSO sessions (forwarded peers — the lead was invited, a peer
 * fills) can open and fill the form but must never reach the panel or the
 * document/evidence downloads.
 */
class InvestigationIntakeSsoTest extends TestCase
{
    use RefreshDatabase;

    private function link(string $email = 'lead@x.com'): array
    {
        $incident = Incident::factory()->create();
        $request = InvestigationFormRequest::create([
            'incident_id' => $incident->id,
            'team' => 'Payments',
            'status' => InvestigationFormRequest::STATUS_PENDING,
            'expires_at' => now()->addDays(14),
            'created_by' => User::factory()->create()->id,
        ]);
        $recipient = $request->recipients()->create(['email' => $email, 'token_hash' => '', 'emailed_at' => now()]);
        $plain = $recipient->linkToken();

        return [$recipient, $plain];
    }

    public function test_guest_is_sent_straight_to_microsoft_with_the_exact_link_intended(): void
    {
        [, $plain] = $this->link();

        $url = route('investigation-form.intake', ['token' => $plain]);

        $this->get($url)->assertRedirect(route('microsoft.redirect'));

        $this->assertSame($url, session('url.intended'), 'the OAuth round trip must land back on the exact link');
    }

    public function test_a_role_less_sso_session_opens_the_form_and_sees_their_identity(): void
    {
        [$recipient, $plain] = $this->link();

        $user = User::factory()->create(['name' => 'Forwarded Peer', 'email' => 'peer@x.com']);

        $this->actingAs($user)
            ->get(route('investigation-form.intake', ['token' => $plain]))
            ->assertOk()
            ->assertSee('Forwarded Peer')
            ->assertSee('peer@x.com');

        $recipient->refresh();
        $this->assertNotNull($recipient->last_opened_at);
    }

    public function test_the_sso_signed_in_fallback_page_greets_the_role_less_user(): void
    {
        $user = User::factory()->create(['name' => 'Forwarded Peer']);

        $this->actingAs($user)
            ->get(route('sso-signed-in'))
            ->assertOk()
            ->assertSee('signed in')
            ->assertSee('Open the investigation form link from your email')
            ->assertSee('Forwarded Peer');
    }

    public function test_a_role_less_session_never_reaches_the_panel(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_a_role_less_session_cannot_download_incident_documents(): void
    {
        $doc = InvestigationDocument::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('documents.download', $doc))->assertForbidden();
    }

    public function test_a_role_less_session_cannot_download_action_evidence(): void
    {
        $action = ActionImprovement::factory()->create(['incident_id' => Incident::factory()->create()->id]);
        $evidence = ActionImprovementEvidence::create([
            'action_improvement_id' => $action->id,
            'file_path' => 'evidence/x.pdf',
            'original_filename' => 'x.pdf',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('action-evidence.download', $evidence))->assertForbidden();
    }
}
