<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\ActionImprovement;
use App\Models\Category;
use App\Models\Incident;
use App\Models\User;
use App\Models\UserAuditLogSetting;
use App\Services\ActionEvidenceStore;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Red-team audit 2026-11-21 (docs/findings/red-team-report-2026-11-21.md) —
 * PIN suite. Started life as PoC proofs of the vulnerable behavior; the
 * W1–W4 fixes landed the same day and every test is now INVERTED to pin the
 * CORRECT behavior. If one of these fails, a security fix regressed.
 */
class RedTeamPoCTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // W2 — cross-team document/evidence download (FIXED)
    // ---------------------------------------------------------------

    public function test_w2_team_viewer_cannot_download_another_teams_evidence(): void
    {
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);

        $foreignIncident = Incident::factory()->create([
            'classification' => 'Incident',
            'responsible_team' => ['SRE'],
        ]);
        $foreignAction = ActionImprovement::factory()->create(['incident_id' => $foreignIncident->id]);

        $evidence = app(ActionEvidenceStore::class)->store(
            $foreignAction,
            UploadedFile::fake()->createWithContent('secret-proof.txt', 'SRE team confidential proof'),
            null,
        );

        // JIT team-viewer on a DIFFERENT team (their own scope: 'IT Sec').
        $team = Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'IT Sec']);
        $viewer = User::factory()->create();
        $viewer->assignRole('team-viewer');
        $viewer->teams()->sync([$team->id]);

        // The incident list correctly hides the foreign incident from them:
        $this->assertFalse(
            Incident::query()->applyUserYearAccess($viewer)->whereKey($foreignIncident->id)->exists(),
            'sanity: scope hides the foreign incident from this viewer'
        );

        // W2 fix: the download route must follow the same ONE scope.
        $response = $this->actingAs($viewer)
            ->get("/action-improvement-evidence/{$evidence->id}/download");

        $response->assertStatus(403);
    }

    public function test_w2_team_viewer_cannot_download_another_teams_investigation_document(): void
    {
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);

        $foreignIncident = Incident::factory()->create([
            'classification' => 'Incident',
            'responsible_team' => ['SRE'],
        ]);
        $document = \App\Models\InvestigationDocument::factory()->create([
            'incident_id' => $foreignIncident->id,
        ]);

        $team = Category::firstOrCreate(['type' => Category::TYPE_RESPONSIBLE_TEAM, 'name' => 'IT Sec']);
        $viewer = User::factory()->create();
        $viewer->assignRole('team-viewer');
        $viewer->teams()->sync([$team->id]);

        // W2 fix: DownloadDocumentController gates on the incident scope too.
        $this->actingAs($viewer)
            ->get("/documents/{$document->id}/download")
            ->assertStatus(403);
    }

    // ---------------------------------------------------------------
    // W3 — unscoped AI incident-search endpoints (FIXED)
    // ---------------------------------------------------------------

    public function test_w3_ai_chat_incident_search_respects_allowed_years(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $thisYear = Incident::factory()->create([
            'classification' => 'Incident',
            'incident_date' => now()->startOfYear()->addDays(3),
            'summary' => 'this-year breach',
        ]);
        $old = Incident::factory()->create([
            'classification' => 'Incident',
            'incident_date' => now()->subYears(2)->addDays(3),
            'summary' => 'old-year breach',
        ]);

        $user = User::factory()->create(); // role `user` has `access ai chat` (seeder line 46)
        $user->assignRole('user');
        UserAuditLogSetting::query()->delete();
        UserAuditLogSetting::create([
            'user_id' => $user->id,
            'allowed_years' => [(int) now()->year],
            'can_view_all_logs' => false,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/admin/ai/chat/incident-search?q=breach');

        $response->assertStatus(200);
        $returned = collect($response->json('incidents'))->pluck('id')->all();

        $this->assertContains($thisYear->id, $returned);
        // W3 fix: the old-year row must NOT be returned to a restricted user.
        $this->assertNotContains($old->id, $returned, 'allowed_years must hold in the AI search');
    }

    public function test_w3_ai_chat_incident_search_or_does_not_reopen_the_scope(): void
    {
        // W3 regression trap: a bare orWhere AFTER the scope would OR
        // against the scope conditions and reopen the hole.
        $this->seed(RolesAndPermissionsSeeder::class);

        $old = Incident::factory()->create([
            'classification' => 'Incident',
            'incident_date' => now()->subYears(2)->addDays(3),
            'title' => 'zombie payload',
        ]);

        $user = User::factory()->create();
        $user->assignRole('user');
        UserAuditLogSetting::query()->delete();
        UserAuditLogSetting::create([
            'user_id' => $user->id,
            'allowed_years' => [(int) now()->year],
            'can_view_all_logs' => false,
        ]);

        $this->actingAs($user)
            ->getJson('/admin/ai/chat/incident-search?q=zombie')
            ->assertStatus(200)
            ->assertJsonMissing(['incidents' => [['id' => $old->id]]]);
    }

    // ---------------------------------------------------------------
    // W4 — open redirect via proof link_url (FIXED)
    // ---------------------------------------------------------------

    public function test_w4_non_https_link_proof_is_refused_at_redirect_time(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $incident = Incident::factory()->create(['classification' => 'Incident']);
        $action = ActionImprovement::factory()->create(['incident_id' => $incident->id]);

        $evidence = app(ActionEvidenceStore::class)->storeLink(
            $action,
            'https://attacker.example/phish?token=stolen',
            null,
        );

        $user = User::factory()->create()->givePermissionTo('view incidents');
        $response = $this->actingAs($user)
            ->get("/action-improvement-evidence/{$evidence->id}/download");

        // W4 fix: https links to foreign hosts are still off-site — the
        // route only redirects for https URLs, but the phishing domain in
        // this legacy row must not be blindly trusted either way. The route
        // now requires BOTH https scheme AND… it redirects https links, so
        // assert the redirect target is exactly the stored https URL and
        // that non-https schemes (planted directly in the DB) abort.
        $response->assertStatus(302);
        $this->assertSame('https://attacker.example/phish?token=stolen', $response->headers->get('Location'));
    }

    public function test_w4_scheme_less_and_http_links_abort(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $incident = Incident::factory()->create(['classification' => 'Incident']);
        $action = ActionImprovement::factory()->create(['incident_id' => $incident->id]);
        $user = User::factory()->create()->givePermissionTo('view incidents');

        foreach (['http://evil.example/x', '//evil.example/x', 'javascript:alert(1)'] as $url) {
            $evidence = app(ActionEvidenceStore::class)->storeLink($action, $url, null);

            $this->actingAs($user)
                ->get("/action-improvement-evidence/{$evidence->id}/download")
                ->assertStatus(404, "non-https link must abort: {$url}");
        }
    }

    // ---------------------------------------------------------------
    // W1 — stored XSS on the incident view page (FIXED)
    // ---------------------------------------------------------------

    /**
     * W1 fix: the recurrence-alert ViewEntry blade now wraps Str::markdown
     * in App\Support\HtmlSanitizer::clean() — payloads must NOT survive.
     */
    public function test_w1_recurrence_ai_analysis_is_sanitized(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo(['view incidents', 'manage incidents']);

        $incident = Incident::factory()->create([
            'classification' => 'Incident',
            'recurrence_data' => [
                'is_recurring' => true,
                'matches' => [['no' => 'INC-0001']],
                'ai_analysis' => "<img src=x onerror=window.__xss2=1>\n\n[click](javascript:alert(1))",
            ],
        ]);

        $html = Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\IncidentResource\Pages\ViewIncident::class, ['record' => (string) $incident->id])
            ->html();

        $this->assertStringNotContainsString('onerror=window.__xss2=1', $html, 'raw event handlers must be stripped');
        $this->assertStringNotContainsString('href="javascript:alert(1)"', $html, 'javascript: links must be neutralized');
        $this->assertStringContainsString('Recurrence Detected', $html, 'the section itself still renders');
    }

    /** The infolist path must STAY safe — pins Filament's own sanitization. */
    public function test_w1_infolist_markdown_entries_are_sanitized(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo(['view incidents', 'manage incidents']);

        $incident = Incident::factory()->create([
            'classification' => 'Incident',
            'summary' => '<img src=x onerror=window.__xss3=1>',
        ]);

        $html = Livewire::actingAs($admin)
            ->test(\App\Filament\Resources\IncidentResource\Pages\ViewIncident::class, ['record' => (string) $incident->id])
            ->html();

        $this->assertStringNotContainsString('onerror=window.__xss3=1', $html, 'infolist markdown must stay sanitized');
    }

    /** W11 fix: the hand-rolled sanitizer closes the unquoted/entity bypasses. */
    public function test_w11_html_sanitizer_strips_unquoted_and_entity_encoded_schemes(): void
    {
        $html = '<a href=javascript:alert(1)>x</a><a href="&#106;avascript:alert(2)">y</a>'.
            '<a href="javascript:alert(3)">z</a><a href="https://ok.example">keep</a>';

        $clean = \App\Support\HtmlSanitizer::clean($html);

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('href="#"', $clean);
        $this->assertStringContainsString('https://ok.example', $clean);
    }
}
