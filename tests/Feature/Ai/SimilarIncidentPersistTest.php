<?php

namespace Tests\Feature\Ai;

use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\IncidentSimilarIncident;
use App\Models\User;
use App\Services\Ai\RagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SimilarIncidentPersistTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Permission::firstOrCreate(['name' => 'manage incidents']);
        $this->user->givePermissionTo('manage incidents');

        config(['ai.api_key' => 'test-key', 'ai.base_url' => 'https://gateway.test']);
    }

    private function makeIncident(array $overrides = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'severity' => Severity::P2,
            'incident_status' => IncidentStatus::Completed,
            'classification' => IncidentClassification::Incident,
            'incident_date' => now()->subMonth(),
            'root_cause' => 'Database connection pool exhaustion under peak load.',
        ], $overrides));
    }

    private function ragHit(int $incidentId, float $score): object
    {
        return (object) ['incident_id' => $incidentId, 'relevance_score' => $score];
    }

    private function gatewayBody(string $content): array
    {
        return [
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['total_tokens' => 20],
        ];
    }

    /**
     * Drive the detect-similar endpoint with a mocked RAG + gateway. The verify
     * phase returns $verifyJson (the matches that should be persisted).
     */
    private function postDetect(int $sourceId, Collection $ragResults, string $verifyJson): TestResponse
    {
        $this->mock(RagService::class, function ($mock) use ($ragResults) {
            $mock->shouldReceive('search')->andReturn($ragResults);
            $mock->shouldReceive('indexIncident'); // ensureIndexed no-op
        });

        Http::fake(function (Request $request) use ($verifyJson) {
            $system = $request->data()['messages'][0]['content'] ?? '';
            if (str_contains($system, 'deep incident analysis engine')) {
                return Http::response($this->gatewayBody('{}'), 200);
            }
            if (str_contains($system, 'rigorous incident similarity verifier')) {
                return Http::response($this->gatewayBody($verifyJson), 200);
            }
            if (str_contains($system, 'strict incident similarity judge')) {
                return Http::response($this->gatewayBody('{"verdicts":[]}'), 200);
            }

            return Http::response($this->gatewayBody('{}'), 200);
        });

        return $this->actingAs($this->user)
            ->postJson('/admin/ai/detect-similar', [
                'exclude_id' => $sourceId,
                'summary' => 'Payment gateway DB pool exhaustion',
            ]);
    }

    private function verifyJson(array $matches): string
    {
        return json_encode(['verified' => $matches]);
    }

    /**
     * Gateway routing for the fallback tests: the pipeline's THINK phase
     * fails (500) while the legacy single-call prompt succeeds and returns
     * one match — so any persisted row proves the legacy path ran.
     */
    private function fakeThinkFailsLegacySucceeds(Incident $candidate): void
    {
        Http::fake(function (Request $request) use ($candidate) {
            $system = $request->data()['messages'][0]['content'] ?? '';

            if (str_contains($system, 'deep incident analysis engine')) {
                return Http::response([], 500);
            }

            if (str_contains($system, 'incident similarity analyst')) {
                return Http::response($this->gatewayBody(json_encode([
                    'similar' => [
                        ['incident_no' => $candidate->no, 'similarity' => 0.75, 'reason' => 'legacy match'],
                    ],
                ])), 200);
            }

            return Http::response($this->gatewayBody('{}'), 200);
        });

        $this->mock(RagService::class, function ($mock) {
            $mock->shouldReceive('search')->andReturn(collect());
            $mock->shouldReceive('indexIncident');
        });
    }

    public function test_pipeline_failure_returns_error_instead_of_silent_legacy_fallback(): void
    {
        // RC4: the loose legacy prompt (counts category overlap as a match)
        // used to run silently whenever the pipeline hiccuped — the owner saw
        // its irrelevant matches with no indication. Default: surface the error.
        config(['ai.similarity.legacy_fallback' => false]);

        $source = $this->makeIncident();
        $candidate = $this->makeIncident(['title' => 'Legacy would match this']);
        $this->fakeThinkFailsLegacySucceeds($candidate);

        $res = $this->actingAs($this->user)->postJson('/admin/ai/detect-similar', [
            'exclude_id' => $source->id,
            'summary' => 'Payment gateway DB pool exhaustion',
        ]);

        $res->assertOk();
        $this->assertFalse($res->json('data.success'), 'pipeline failure must not report success');
        $this->assertNotEmpty($res->json('data.error'), 'the pipeline error must reach the client');
        $this->assertSame(0, IncidentSimilarIncident::where('incident_id', $source->id)->count(), 'loose legacy matches must not be persisted');
    }

    public function test_legacy_fallback_still_available_when_explicitly_enabled(): void
    {
        config(['ai.similarity.legacy_fallback' => true]);

        $source = $this->makeIncident();
        $candidate = $this->makeIncident(['title' => 'Legacy would match this']);
        $this->fakeThinkFailsLegacySucceeds($candidate);

        $res = $this->actingAs($this->user)->postJson('/admin/ai/detect-similar', [
            'exclude_id' => $source->id,
            'summary' => 'Payment gateway DB pool exhaustion',
        ]);

        $res->assertOk();
        $this->assertTrue($res->json('data.success'));
        $this->assertSame(1, IncidentSimilarIncident::where('incident_id', $source->id)
            ->where('similar_incident_id', $candidate->id)->count(), 'enabled fallback must persist its match');
    }

    public function test_similar_card_displays_match_type_badge(): void
    {
        // RC5: 'deep' (same root cause) vs 'thematic' (related, different cause)
        // is stored but was never shown — thematic junk at 65% read as authoritative.
        config(['ai.api_key' => 'test-key', 'ai.base_url' => 'https://gateway.test']);

        $html = view('filament.forms.components.ai-similar-incidents')->render();

        $this->assertStringContainsString('match_type', $html, 'card must render a match-type badge');
    }

    public function test_persist_via_service_creates_prunes_and_preserves_dismissed(): void
    {
        // B1: the re-verify semantics live on the service (single home) so the
        // auto-detection job and the on-demand button share them exactly.
        $source = $this->makeIncident();
        $detected = $this->makeIncident(['title' => 'Still similar']);
        $dismissed = $this->makeIncident(['title' => 'Admin-dismissed']);
        $stale = $this->makeIncident(['title' => 'Stale auto row']);

        IncidentSimilarIncident::create([
            'incident_id' => $source->id,
            'similar_incident_id' => $dismissed->id,
            'similarity' => 0.5,
            'dismissed_at' => now(),
            'dismissed_by' => $this->user->id,
        ]);
        IncidentSimilarIncident::create([
            'incident_id' => $source->id,
            'similar_incident_id' => $stale->id,
            'similarity' => 0.4,
        ]);

        app(\App\Services\Ai\SimilarIncidentService::class)->persist($source, [
            ['id' => $detected->id, 'similarity' => 0.8, 'match_type' => 'deep', 'reason' => 'same root cause', 'dimensions' => []],
        ]);

        $this->assertTrue(IncidentSimilarIncident::where('incident_id', $source->id)->where('similar_incident_id', $detected->id)->exists(), 'detected match must be created');
        $this->assertTrue(IncidentSimilarIncident::where('incident_id', $source->id)->where('similar_incident_id', $dismissed->id)->whereNotNull('dismissed_at')->exists(), 'admin-dismissed history must survive');
        $this->assertFalse(IncidentSimilarIncident::where('incident_id', $source->id)->where('similar_incident_id', $stale->id)->exists(), 'stale auto row must be pruned');
    }

    public function test_index_returns_active_matches_with_row_id(): void
    {
        $source = $this->makeIncident();
        $active = $this->makeIncident(['title' => 'Active match']);
        $dismissed = $this->makeIncident(['title' => 'Dismissed match']);

        $activeRow = IncidentSimilarIncident::create([
            'incident_id' => $source->id,
            'similar_incident_id' => $active->id,
            'similarity' => 0.8,
        ]);
        IncidentSimilarIncident::create([
            'incident_id' => $source->id,
            'similar_incident_id' => $dismissed->id,
            'similarity' => 0.6,
            'dismissed_at' => now(),
            'dismissed_by' => $this->user->id,
        ]);

        $res = $this->actingAs($this->user)->getJson("/admin/ai/incidents/{$source->id}/similar");

        $res->assertOk();
        $similar = $res->json('data.similar');
        $this->assertCount(1, $similar);
        $this->assertSame($active->id, $similar[0]['id']);
        $this->assertSame($activeRow->id, $similar[0]['row_id']);
    }

    public function test_destroy_dismisses_match_and_hides_it_from_index(): void
    {
        $source = $this->makeIncident();
        $candidate = $this->makeIncident();
        $row = IncidentSimilarIncident::create([
            'incident_id' => $source->id,
            'similar_incident_id' => $candidate->id,
            'similarity' => 0.7,
        ]);

        $res = $this->actingAs($this->user)
            ->deleteJson("/admin/ai/incidents/{$source->id}/similar/{$row->id}");

        $res->assertOk();
        $this->assertNotNull($row->fresh()->dismissed_at);
        $this->assertSame($this->user->id, $row->fresh()->dismissed_by);

        $this->actingAs($this->user)
            ->getJson("/admin/ai/incidents/{$source->id}/similar")
            ->assertJsonPath('data.similar', []);
    }

    public function test_detect_persists_verified_matches(): void
    {
        $source = $this->makeIncident();
        $candidate = $this->makeIncident(['title' => 'Payment gateway DB pool exhaustion']);

        $res = $this->postDetect(
            $source->id,
            collect([$this->ragHit($candidate->id, 0.9)]),
            $this->verifyJson([['id' => $candidate->id, 'similarity' => 0.8, 'verified' => true, 'reasoning' => 'same root cause', 'match_type' => 'deep']]),
        );

        $res->assertOk();

        $row = IncidentSimilarIncident::where('incident_id', $source->id)
            ->where('similar_incident_id', $candidate->id)
            ->first();

        $this->assertNotNull($row, 'verified match must be persisted');
        $this->assertSame(0.8, (float) $row->similarity);
        $this->assertNull($row->dismissed_at);
        $this->assertSame($row->id, $res->json('data.similar.0.row_id'));
    }

    public function test_reverify_resurfaces_a_previously_dismissed_pair(): void
    {
        $source = $this->makeIncident();
        $candidate = $this->makeIncident(['title' => 'Payment gateway DB pool exhaustion']);

        // Admin previously dismissed this pairing.
        $row = IncidentSimilarIncident::create([
            'incident_id' => $source->id,
            'similar_incident_id' => $candidate->id,
            'similarity' => 0.5,
            'dismissed_at' => now(),
            'dismissed_by' => $this->user->id,
        ]);

        $this->postDetect(
            $source->id,
            collect([$this->ragHit($candidate->id, 0.9)]),
            $this->verifyJson([['id' => $candidate->id, 'similarity' => 0.8, 'verified' => true, 'reasoning' => 'same root cause', 'match_type' => 'deep']]),
        )->assertOk();

        $this->assertNull($row->fresh()->dismissed_at, 're-verify must reactivate a still-similar dismissed pair');
        $this->assertNull($row->fresh()->dismissed_by);

        $this->actingAs($this->user)
            ->getJson("/admin/ai/incidents/{$source->id}/similar")
            ->assertJsonPath('data.similar.0.id', $candidate->id);
    }

    public function test_reverify_prunes_stale_auto_rows_but_preserves_admin_dismissed(): void
    {
        $source = $this->makeIncident();
        $detected = $this->makeIncident(['title' => 'Payment gateway DB pool exhaustion']);
        $dismissed = $this->makeIncident(['title' => 'Admin-dismissed']);
        $stale = $this->makeIncident(['title' => 'Stale auto-detected']);

        IncidentSimilarIncident::create([
            'incident_id' => $source->id,
            'similar_incident_id' => $dismissed->id,
            'similarity' => 0.5,
            'dismissed_at' => now(),
            'dismissed_by' => $this->user->id,
        ]);
        IncidentSimilarIncident::create([
            'incident_id' => $source->id,
            'similar_incident_id' => $stale->id,
            'similarity' => 0.4,
        ]);

        $this->postDetect(
            $source->id,
            collect([$this->ragHit($detected->id, 0.9)]),
            $this->verifyJson([['id' => $detected->id, 'similarity' => 0.8, 'verified' => true, 'reasoning' => 'same root cause', 'match_type' => 'deep']]),
        )->assertOk();

        // Detected -> created/active.
        $this->assertTrue(IncidentSimilarIncident::where('incident_id', $source->id)->where('similar_incident_id', $detected->id)->exists());
        // Admin-dismissed preserved.
        $this->assertTrue(IncidentSimilarIncident::where('incident_id', $source->id)->where('similar_incident_id', $dismissed->id)->whereNotNull('dismissed_at')->exists());
        // Stale auto row pruned.
        $this->assertFalse(IncidentSimilarIncident::where('incident_id', $source->id)->where('similar_incident_id', $stale->id)->exists());
    }
}
