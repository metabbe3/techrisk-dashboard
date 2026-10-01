<?php

namespace Tests\Unit\Jobs;

use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Jobs\Ai\DetectSimilarIncidentsJob;
use App\Models\Incident;
use App\Models\IncidentSimilarIncident;
use App\Services\Ai\SimilarIncidentResult;
use App\Services\Ai\SimilarIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * B2: every new incident auto-detects similar incidents with scores (owner
 * 2026-10-01 — "every new incident in incident detail have same incident
 * with score"). Queued off the observer; the pipeline + persist semantics
 * are the same ones the on-demand button uses.
 */
class DetectSimilarIncidentsJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeIncident(array $overrides = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'severity' => Severity::P2,
            'incident_status' => IncidentStatus::Completed,
            'classification' => IncidentClassification::Incident,
            'incident_date' => now()->subMonth(),
        ], $overrides));
    }

    public function test_incident_creation_dispatches_the_job(): void
    {
        Queue::fake();

        $this->makeIncident(['title' => 'Payment gateway timeout']);

        Queue::assertPushed(DetectSimilarIncidentsJob::class);
    }

    public function test_job_analyzes_and_persists_matches(): void
    {
        $source = $this->makeIncident();
        $candidate = $this->makeIncident(['title' => 'Payment gateway DB pool exhaustion']);

        $this->mock(SimilarIncidentService::class, function ($mock) use ($source, $candidate) {
            $mock->shouldReceive('isAvailable')->andReturn(true);
            $mock->shouldReceive('analyze')->andReturn(SimilarIncidentResult::success(
                matches: [[
                    'id' => $candidate->id,
                    'no' => $candidate->no,
                    'title' => $candidate->title,
                    'similarity' => 0.82,
                    'match_type' => 'deep',
                    'reasoning' => 'same DB pool exhaustion mechanism',
                    'dimensions' => [],
                ]],
                model: 'REASONING-MODEL',
            ));
            $mock->shouldReceive('persist')->once()->with(
                \Mockery::on(fn ($arg) => $arg->is($source)),
                \Mockery::on(fn ($matches) => ($matches[0]['id'] ?? null) === $candidate->id),
            );
        });

        (new DetectSimilarIncidentsJob($source))->handle(app(SimilarIncidentService::class));
    }

    public function test_job_skips_when_ai_is_unavailable(): void
    {
        $source = $this->makeIncident();

        $this->mock(SimilarIncidentService::class, function ($mock) {
            $mock->shouldReceive('isAvailable')->andReturn(false);
            $mock->shouldReceive('analyze')->never();
            $mock->shouldReceive('persist')->never();
        });

        (new DetectSimilarIncidentsJob($source))->handle(app(SimilarIncidentService::class));

        $this->assertSame(0, IncidentSimilarIncident::count(), 'nothing may be written when AI is unavailable');
    }
}
