<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Incident;
use App\Services\Ai\ChatContextService;
use App\Services\Ai\HybridIncidentRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ChatContextServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChatContextService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('chat_label_names');
        config([
            'ai.prompt_optimization.enabled' => false,
            'ai.chat.relevant_incidents.enabled' => true,
            'ai.chat.relevant_incidents.min_length' => 15,
        ]);
        $this->service = app(ChatContextService::class);
    }

    public function test_smart_search_caps_results_and_reports_true_total(): void
    {
        Incident::factory()->count(60)->create([
            'classification' => 'Incident',
            'severity' => 'P3',
            'incident_date' => now()->subDays(rand(0, 30)),
        ]);

        $context = $this->service->smartSearchContext('P3 incidents');

        $this->assertStringContainsString('60 incidents found', $context);
        $this->assertStringContainsString('showing 50 most recent of 60', $context);
        $this->assertStringContainsString('stats below cover the shown subset only', $context);
    }

    public function test_smart_search_uses_explicit_month_year_not_current_year(): void
    {
        Incident::factory()->create([
            'classification' => 'Incident',
            'incident_date' => '2025-01-15 10:00:00',
            'title' => 'Explicit year incident',
        ]);
        Incident::factory()->create([
            'classification' => 'Incident',
            'incident_date' => now()->startOfYear()->addDays(14)->format('Y-m-d H:i:s'),
            'title' => 'Current year January incident',
        ]);

        $context = $this->service->smartSearchContext('incidents in January 2025');

        $this->assertStringContainsString('date=2025-01-01 to 2025-01-31', $context);
        $this->assertStringContainsString('Explicit year incident', $context);
        $this->assertStringNotContainsString('Current year January incident', $context);
    }

    public function test_smart_search_q_token_with_explicit_year(): void
    {
        $context = $this->service->smartSearchContext('incidents during Q2 2024');

        $this->assertStringContainsString('date=2024-04-01 to 2024-06-30', $context);
    }

    public function test_system_prompt_injects_no_retrieval_sentinel_when_retrieval_is_empty(): void
    {
        $retriever = $this->createMock(HybridIncidentRetriever::class);
        $retriever->method('retrieveForQuery')->willReturn(collect([]));
        $this->app->instance(HybridIncidentRetriever::class, $retriever);

        $prompt = $this->service->buildSystemPrompt('what incidents relate to payment gateway outages?');

        $this->assertStringContainsString('NO RETRIEVAL RESULTS', $prompt);
        $this->assertStringContainsString('do not estimate or invent numbers', $prompt);
    }

    public function test_system_prompt_omits_sentinel_for_short_messages(): void
    {
        $retriever = $this->createMock(HybridIncidentRetriever::class);
        $retriever->method('retrieveForQuery')->willReturn(collect([]));
        $this->app->instance(HybridIncidentRetriever::class, $retriever);

        $prompt = $this->service->buildSystemPrompt('hi there');

        $this->assertStringNotContainsString('NO RETRIEVAL RESULTS', $prompt);
    }

    // ponytail: getCompareContext (/compare) can't run under sqlite — it
    // groups by MONTH() and FIELD()-sorts, both MySQL-only — so its AVG-CASE
    // eligibility fix is verified by reading. The same invariant is covered
    // runnable here through getQuickStats.

    public function test_quick_stats_avg_mttr_skips_ineligible_and_day_encoded(): void
    {
        Cache::forget('chat_quick_stats_v2');

        // Eligible minutes-encoded row (60-min bleed window) — the only one
        // AVG may count. mttr is observer-computed from stop_bleeding_at.
        Incident::factory()->create([
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'severity' => 'P2',
            'incident_date' => now()->startOfYear()->setDay(5)->setTime(10, 0),
            'stop_bleeding_at' => now()->startOfYear()->setDay(5)->setTime(11, 0),
        ]);

        // Day-encoded negative (Confirmed loss + multi-day bleed) — must not
        // drag the minutes average.
        Incident::factory()->create([
            'classification' => 'Incident',
            'fund_status' => 'Confirmed loss',
            'severity' => 'P2',
            'incident_date' => now()->startOfYear()->setDay(6)->setTime(10, 0),
            'stop_bleeding_at' => now()->startOfYear()->setDay(9)->setTime(10, 0),
        ]);

        // G severity: never counted (2026-09-17 rule) — not in MTTR, not in totals.
        Incident::factory()->create([
            'classification' => 'Incident',
            'fund_status' => 'Non fundLoss',
            'severity' => 'G',
            'incident_date' => now()->startOfYear()->setDay(7)->setTime(10, 0),
            'stop_bleeding_at' => now()->startOfYear()->setDay(7)->setTime(10, 30),
        ]);

        $stats = $this->service->getQuickStats();

        $this->assertStringContainsString('Avg MTTR: 60.0 minutes', $stats);
        $this->assertStringContainsString('Total Incidents ('.now()->year.'): 2', $stats);
    }
}
