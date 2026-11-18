<?php

namespace Tests\Unit\Services;

use App\Jobs\DetectRecurrenceJob;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\Label;
use App\Services\Ai\RagService;
use App\Services\RecurrenceDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class RecurrenceDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecurrenceDetectionService $service;

    /** @var Mockery\MockInterface|RagService */
    private $rag;

    protected function setUp(): void
    {
        parent::setUp();
        // AI unavailable → category_match path + deterministic fallback analysis
        config(['ai.base_url' => null, 'ai.api_key' => null]);

        // RAG mock: empty by default, per-test `shouldReceive` to inject raw
        // MATCH…AGAINST relevance (SQLite has no MySQL fulltext functions).
        $this->rag = Mockery::mock(RagService::class);
        $this->rag->shouldReceive('search')->andReturn(new Collection)->byDefault();
        $this->app->instance(RagService::class, $this->rag);

        $this->service = app(RecurrenceDetectionService::class);
    }

    private function incident(array $attrs = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'severity' => 'P1',
            'classification' => 'Incident',
            'root_cause_category' => ['Settlement'],
            'business_category' => [],
            'responsible_team' => ['CFM'],
            'incident_date' => now()->subDays(5),
        ], $attrs));
    }

    public function test_action_counts_use_open_statuses_not_hand_typed_strings(): void
    {
        $incident = $this->incident();
        $other = $this->incident();

        // pending (future due) + on_progress (past due) + done + tbd/no due
        ActionImprovement::factory()->for($other)->createMany([
            ['title' => 'a', 'status' => ActionImprovement::STATUS_PENDING, 'due_date' => now()->addWeek()],
            ['title' => 'b', 'status' => ActionImprovement::STATUS_ON_PROGRESS, 'due_date' => now()->subWeek()],
            ['title' => 'c', 'status' => ActionImprovement::STATUS_DONE, 'due_date' => now()->subWeek()],
            ['title' => 'd', 'status' => ActionImprovement::STATUS_TBD, 'due_date' => null],
        ]);

        $result = $this->service->detect($incident);

        $match = collect($result['matches'])->firstWhere('id', $other->id);
        $this->assertNotNull($match);
        $this->assertSame(2, $match['pending_actions']);
        $this->assertSame(1, $match['overdue_actions']);
    }

    public function test_raw_rag_relevance_is_normalized_and_score_capped(): void
    {
        $incident = $this->incident();
        $other = $this->incident();

        $doc = new \stdClass;
        $doc->incident_id = $other->id;
        $doc->relevance_score = 70.0; // raw MATCH…AGAINST score, unbounded
        $this->rag->shouldReceive('search')->andReturn(new Collection([$doc]));

        $result = $this->service->detect($incident);

        $match = collect($result['matches'])->firstWhere('id', $other->id);
        $this->assertNotNull($match);
        $this->assertLessThanOrEqual(10, $match['score']);
        $this->assertContains('text_similarity', $match['match_reasons']);
    }

    public function test_team_and_severity_overlap_alone_do_not_flag_recurrence(): void
    {
        $incident = $this->incident();
        $other = $this->incident(['root_cause_category' => ['Database'], 'responsible_team' => ['CFM'], 'severity' => 'P1']);

        $result = $this->service->detect($incident);

        $this->assertFalse($result['is_recurring'] ?? false);
    }

    public function test_root_cause_category_overlap_flags_recurrence(): void
    {
        $incident = $this->incident();
        $other = $this->incident();

        $result = $this->service->detect($incident);

        $this->assertTrue($result['is_recurring']);
        $this->assertSame('category_match', $result['detection_method']);
    }

    public function test_matches_carry_title_and_root_cause_for_the_ai_prompt(): void
    {
        $incident = $this->incident();
        $other = $this->incident(['root_cause' => 'Unsettled POS reconciliation gap']);

        $result = $this->service->detect($incident);

        $match = collect($result['matches'])->firstWhere('id', $other->id);
        $this->assertSame($other->title, $match['title']);
        $this->assertStringContainsString('Unsettled POS', $match['root_cause']);
        $this->assertContains('root_cause_category', $match['match_reasons']);
    }

    public function test_label_overlap_flags_recurrence(): void
    {
        $label = Label::firstOrCreate(['name' => 'unsettled']);
        $incident = $this->incident(['root_cause_category' => []]);
        $incident->labels()->attach($label);
        $other = $this->incident(['root_cause_category' => []]);
        $other->labels()->attach($label);

        $result = $this->service->detect($incident);

        $this->assertTrue($result['is_recurring']);
        $match = collect($result['matches'])->firstWhere('id', $other->id);
        $this->assertContains('labels', $match['match_reasons']);
    }

    public function test_observer_redispatches_when_root_cause_changes_later(): void
    {
        $incident = $this->incident(['root_cause' => 'initial']);
        Queue::fake(); // reset — create() itself dispatches the 30s-delayed detect job

        $incident->update(['root_cause' => 'deeper analysis']);

        // Force-flagged: re-detects even though recurrence_data exists.
        Queue::assertPushed(DetectRecurrenceJob::class, fn (DetectRecurrenceJob $job) => $job->force === true);
    }

    public function test_observer_does_not_redispatch_on_team_only_change_when_already_analyzed(): void
    {
        $incident = $this->incident(['recurrence_data' => ['analyzed_at' => now()->toIso8601String()]]);
        Queue::fake(); // reset — create() itself dispatches the 30s-delayed detect job

        $incident->update(['responsible_team' => ['Other Team']]);

        Queue::assertNotPushed(DetectRecurrenceJob::class);
    }
}
