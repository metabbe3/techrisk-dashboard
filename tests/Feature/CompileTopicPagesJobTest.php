<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\Ai\CompileTopicPagesJob;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The LLM-Wiki-style compile step (owner-approved pilot 2026-11-17): a job
 * that synthesizes one pattern page per responsible team out of the incident
 * corpus scope, citing incident numbers, writing corpus/topics/*.md +
 * topics/index.md. Pages are regenerated from source — never hand-edited; an
 * LLM failure must keep the previous page (never wipe compiled knowledge, the
 * same rule as the Go knowledge digest).
 */
class CompileTopicPagesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The local disk is a real filesystem — pattern pages leak between
        // tests without this (RefreshDatabase only resets tables).
        Storage::disk('local')->deleteDirectory('markdown/corpus');
    }

    private function incident(string $no, string $team, string $title, string $rootCause): Incident
    {
        return Incident::factory()->create([
            'no' => $no,
            'title' => $title,
            'root_cause' => $rootCause,
            'responsible_team' => [$team], // array cast — PROJ-010 multi-value
            'classification' => 'Incident',
            'severity' => 'P1',
            'incident_status' => \App\Enums\IncidentStatus::Completed->value,
            'root_cause_category' => ['Technical'],
            'stop_bleeding_at' => now(),
        ]);
    }

    private function fakeBrain(string $output): void
    {
        config([
            'ai.go_agent.base_url' => 'http://go-brain.test',
            'ai.go_agent.token' => 'token-1',
        ]);
        Http::fake(['go-brain.test/internal/run' => Http::response([
            'ok' => true, 'output' => $output, 'model' => 'm',
        ])]);
    }

    public function test_synthesizes_pattern_pages_per_team_with_incident_numbers(): void
    {
        $this->incident('2026_IN_0001', 'Payments', 'Gateway timeout at checkout', 'Connection pool exhausted');
        $this->incident('2026_IN_0002', 'Payments', 'Gateway timeout again', 'Connection pool exhausted');
        $this->incident('2026_IN_0003', 'Cards', 'Batch file rejected', 'Wrong schema version');
        $this->incident('2026_IN_0004', 'Cards', 'Batch rejected in UAT', 'Schema mismatch');

        $this->fakeBrain('## Recurring: gateway timeouts');
        Http::assertSentCount(0);

        (new CompileTopicPagesJob)->handle(app(\App\Services\Ai\GoAgentBrain::class));

        Storage::disk('local')->assertExists('markdown/corpus/topics/payments.md');
        Storage::disk('local')->assertExists('markdown/corpus/topics/cards.md');
        $payments = Storage::disk('local')->get('markdown/corpus/topics/payments.md');
        $this->assertStringContainsString('Recurring: gateway timeouts', $payments);
        // Provenance header — regenerated from source, never hand-edited.
        $this->assertStringContainsString('Regenerated automatically', $payments);

        $index = Storage::disk('local')->get('markdown/corpus/topics/index.md');
        $this->assertStringContainsString('(payments.md)', $index);
        $this->assertStringContainsString('(cards.md)', $index);

        // The synthesis prompt carried the incident data and cited-number rule.
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $text = (string) ($body['text'] ?? '');

            return str_contains($text, '2026_IN_0001')
                && str_contains($text, 'Connection pool exhausted')
                && str_contains((string) ($body['system'] ?? ''), 'incident number')
                && str_contains((string) ($body['system'] ?? ''), 'UNTRUSTED');
        });
    }

    public function test_teams_with_a_single_incident_get_no_page(): void
    {
        $this->incident('2026_IN_0001', 'Payments', 'One-off', 'Cause');
        $this->incident('2026_IN_0002', 'Cards', 'A', 'C1');
        $this->incident('2026_IN_0003', 'Cards', 'B', 'C2');

        $this->fakeBrain('body');

        (new CompileTopicPagesJob)->handle(app(\App\Services\Ai\GoAgentBrain::class));

        Storage::disk('local')->assertMissing('markdown/corpus/topics/payments.md');
        Storage::disk('local')->assertExists('markdown/corpus/topics/cards.md');
        $index = Storage::disk('local')->get('markdown/corpus/topics/index.md');
        $this->assertStringNotContainsString('payments.md', $index);
    }

    public function test_llm_failure_keeps_the_previous_page(): void
    {
        $this->incident('2026_IN_0001', 'Payments', 'Gateway timeout', 'Pool exhausted');
        $this->incident('2026_IN_0002', 'Payments', 'Gateway timeout 2', 'Pool exhausted');

        $this->fakeBrain('good body');
        (new CompileTopicPagesJob)->handle(app(\App\Services\Ai\GoAgentBrain::class));
        Storage::disk('local')->assertExists('markdown/corpus/topics/payments.md');

        // Brain unreachable this round: the old page must survive untouched.
        config(['ai.go_agent.base_url' => 'http://127.0.0.1:1']);
        (new CompileTopicPagesJob)->handle(app(\App\Services\Ai\GoAgentBrain::class));

        Storage::disk('local')->assertExists('markdown/corpus/topics/payments.md');
        $this->assertStringContainsString(
            'good body',
            Storage::disk('local')->get('markdown/corpus/topics/payments.md'),
        );
        Storage::disk('local')->assertExists('markdown/corpus/topics/index.md');
        $index = Storage::disk('local')->get('markdown/corpus/topics/index.md');
        $this->assertStringContainsString('(payments.md)', $index, 'old page stays listed');
    }

    public function test_regen_skips_g_and_non_incident_rows(): void
    {
        $this->incident('2026_IN_0001', 'Payments', 'A', 'C1');
        $this->incident('2026_IN_0002', 'Payments', 'B', 'C2');
        // G / Non-Incident rows are never corpus content (aiCounts rule) —
        // a team that ONLY has those gets no page and no index line.
        $this->incident('2026_G_0003', 'Cards', 'G row', 'C3')
            ->update(['severity' => 'G']);
        $this->incident('2026_IS_0004', 'Cards', 'Issue row', 'C4')
            ->update(['classification' => 'Issue']);

        $this->fakeBrain('body');

        (new CompileTopicPagesJob)->handle(app(\App\Services\Ai\GoAgentBrain::class));

        Storage::disk('local')->assertMissing('markdown/corpus/topics/cards.md');
        Storage::disk('local')->assertExists('markdown/corpus/topics/payments.md');
    }
}
