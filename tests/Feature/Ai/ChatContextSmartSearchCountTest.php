<?php

namespace Tests\Feature\Ai;

use App\Enums\Severity;
use App\Models\Incident;
use App\Services\Ai\ChatContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Smart-search totals must follow the same counting rule as Quick Stats
 * (aiCounts/countEligible): severity G and Non Incident never count, no
 * matter the classification branch the parsed filters take. This locks the
 * executeFilterQuery drift fix (excludedFromCounts → countEligible).
 */
class ChatContextSmartSearchCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function seedIncident(array $overrides = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'severity' => Severity::P3->value,
            'classification' => 'Incident',
            'incident_date' => '2026-03-01 10:00:00',
            'incident_status' => 'Open',
            'fund_status' => 'Non fundLoss',
        ], $overrides));
    }

    public function test_incident_ask_excludes_glitch_and_non_incident_severity(): void
    {
        $this->seedIncident();
        $this->seedIncident(['severity' => Severity::G->value, 'incident_date' => '2026-03-02 10:00:00']);
        $this->seedIncident(['severity' => Severity::NonIncident->value, 'incident_date' => '2026-03-03 10:00:00']);

        $context = app(ChatContextService::class)->smartSearchContext('how many incidents');

        $this->assertStringContainsString('(1 incidents found)', $context);
        $this->assertStringContainsString('class=Incident', $context);
    }

    public function test_issue_ask_excludes_glitch_severity(): void
    {
        // The explicit-classification branch is the one that used excludedFromCounts.
        $this->seedIncident(['classification' => 'Issue']);
        $this->seedIncident(['classification' => 'Issue', 'severity' => Severity::G->value]);

        $context = app(ChatContextService::class)->smartSearchContext('how many issues');

        $this->assertStringContainsString('(1 incidents found)', $context);
        $this->assertStringContainsString('class=Issue', $context);
    }

    public function test_excluded_fund_status_rows_do_not_count_in_either_branch(): void
    {
        $this->seedIncident();
        $this->seedIncident(['fund_status' => 'Potential recovery']);
        $this->seedIncident(['classification' => 'Issue']);
        $this->seedIncident(['classification' => 'Issue', 'fund_status' => 'Fully recovered']);

        $incidentContext = app(ChatContextService::class)->smartSearchContext('how many incidents');
        $issueContext = app(ChatContextService::class)->smartSearchContext('how many issues');

        $this->assertStringContainsString('(1 incidents found)', $incidentContext);
        $this->assertStringContainsString('(1 incidents found)', $issueContext);
    }
}
