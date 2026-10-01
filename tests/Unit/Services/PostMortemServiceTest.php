<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Services\Ai\AiTextService;
use App\Services\Ai\PostMortemService;
use App\Services\Markdown\IncidentMarkdownExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * D2: generateAsMarkdown wraps the existing generate() array (7 sections,
 * untouched) into markdown for the retro column on the incident. Null when
 * the AI returned no substance (blank executive summary).
 */
class PostMortemServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(array $aiResult, ?string &$usedModel = null): PostMortemService
    {
        $ai = $this->mock(AiTextService::class, function (MockInterface $mock) use ($aiResult, &$usedModel) {
            $mock->shouldReceive('callAiForJson')
                ->andReturnUsing(function (string $type, string $model) use ($aiResult, &$usedModel) {
                    $usedModel = $model;

                    return $aiResult;
                });
        });

        return new PostMortemService($ai, app(IncidentMarkdownExporter::class));
    }

    private function makeIncident(): Incident
    {
        return Incident::factory()->create([
            'severity' => Severity::P2,
            'incident_status' => IncidentStatus::Completed,
            'classification' => IncidentClassification::Incident,
            'incident_date' => now()->subMonth(),
        ]);
    }

    public function test_generates_markdown_from_the_seven_section_array(): void
    {
        $incident = $this->makeIncident();

        $service = $this->makeService([
            'executive_summary' => 'Payment gateway outage caused by pool exhaustion.',
            'timeline_analysis' => 'Detection at 10:00, mitigation at 10:40.',
            'root_cause_deep_dive' => 'Connection leak under retry storm.',
            'impact_assessment' => [
                'users_affected' => '1.2k merchants',
                'systems_affected' => 'Payment API',
                'financial_impact' => 'Rp 5.000.000',
                'reputation_impact' => 'Limited',
            ],
            'lessons_learned' => ['Add pool saturation alerts', 'Cap retry bursts'],
            'recommendations' => ['Raise pool ceiling', 'Backoff jitter'],
            'severity_assessment' => 'Severity P2 confirmed.',
        ], $usedModel);

        $md = $service->generateAsMarkdown($incident);

        $this->assertNotNull($md);
        $this->assertStringContainsString("# Retrospective — {$incident->no}", $md);
        $this->assertStringContainsString('## Executive Summary', $md);
        $this->assertStringContainsString('Payment gateway outage caused by pool exhaustion.', $md);
        $this->assertStringContainsString('## Timeline Analysis', $md);
        $this->assertStringContainsString('## Root Cause Deep Dive', $md);
        $this->assertStringContainsString('## Impact Assessment', $md);
        $this->assertStringContainsString('- **Financial**: Rp 5.000.000', $md);
        $this->assertStringContainsString('## Lessons Learned', $md);
        $this->assertStringContainsString('- Add pool saturation alerts', $md);
        $this->assertStringContainsString('## Recommendations', $md);
        $this->assertStringContainsString('- Raise pool ceiling', $md);
        $this->assertStringContainsString('## Severity Assessment', $md);
        $this->assertSame(config('ai.default_model'), $usedModel);
    }

    public function test_returns_null_when_ai_result_has_no_substance(): void
    {
        $service = $this->makeService([
            'executive_summary' => '',
            'timeline_analysis' => '',
            'root_cause_deep_dive' => '',
            'impact_assessment' => [],
            'lessons_learned' => [],
            'recommendations' => [],
            'severity_assessment' => '',
        ]);

        $this->assertNull($service->generateAsMarkdown($this->makeIncident()));
    }
}
