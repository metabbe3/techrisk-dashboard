<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\IncidentStatus;
use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\User;
use App\Support\MyIncidents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * THE one definition of "incidents this user handles" (owner 2026-11-20):
 * PIC on the Source & Assignment tab. Scope is WIDER than the portal
 * counting rules — any classification (Issue), any severity (G / Non
 * Incident), sub-incidents included. "Active" (menu count) = not fully
 * done: still running, or Completed but action improvements are open.
 */
class MyIncidentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_every_record_where_user_is_pic(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Issue classification + severity G: OUTSIDE the portal counting rules
        // but INSIDE "everything I handle".
        $issue = Incident::factory()->create(['classification' => 'Issue', 'severity' => 'G']);
        $sub = Incident::factory()->create(['classification' => 'Incident', 'severity' => 'Non Incident']);
        $mine = Incident::factory()->create();
        $mine->pics()->attach($user);
        $issue->pics()->attach($user);
        $sub->pics()->attach($user);
        $someoneElses = Incident::factory()->create();
        $someoneElses->pics()->attach($other);

        $this->assertSame(3, MyIncidents::notFullyDone(MyIncidents::query($user))->count());
    }

    public function test_sub_incident_where_user_is_pic_counts(): void
    {
        $user = User::factory()->create();
        $super = Incident::factory()->create();
        $sub = Incident::factory()->subOf($super)->create();

        $this->assertSame(0, MyIncidents::notFullyDone(MyIncidents::query($user))->count());

        $sub->pics()->attach($user);
        $this->assertSame(1, MyIncidents::notFullyDone(MyIncidents::query($user))->count());
    }

    public function test_active_count_tier_boundaries(): void
    {
        $user = User::factory()->create();

        $running = Incident::factory()->create(['incident_status' => IncidentStatus::InProgress]);
        $finalizing = Incident::factory()->create(['incident_status' => IncidentStatus::Finalization]);
        // The completed variants need the closure-gate fields (PROJ-011: RCA +
        // stop-bleeding time) — created outside the observer, exactly like
        // the prod paths that bypass the form (imports, syncs).
        $completedClean = Incident::withoutEvents(fn () => Incident::factory()->create(['incident_status' => IncidentStatus::Completed]));
        $completedWithOpenAi = Incident::withoutEvents(fn () => Incident::factory()->create(['incident_status' => IncidentStatus::Completed]));
        $completedAllAiDone = Incident::withoutEvents(fn () => Incident::factory()->create(['incident_status' => IncidentStatus::Completed]));

        foreach ([$running, $finalizing, $completedClean, $completedWithOpenAi, $completedAllAiDone] as $incident) {
            $incident->pics()->attach($user);
        }

        ActionImprovement::factory()->create([
            'incident_id' => $completedWithOpenAi->id,
            'status' => ActionImprovement::STATUS_PENDING,
        ]);
        ActionImprovement::factory()->create([
            'incident_id' => $completedAllAiDone->id,
            'status' => ActionImprovement::STATUS_DONE,
        ]);

        // running + finalizing + completed-with-open-AI; the clean completed
        // and the all-done-AI one are OUT (owner: "selesai termasuk action
        // improvement semua done").
        $this->assertSame(3, MyIncidents::activeCount($user));
    }

    public function test_graph_day_takes_the_highest_priority_tier(): void
    {
        $user = User::factory()->create();
        $sameDay = Carbon::today()->setTime(10, 0);

        $running = Incident::factory()->create(['incident_status' => IncidentStatus::Open, 'incident_date' => $sameDay]);
        $monitoring = Incident::withoutEvents(fn () => Incident::factory()->create(['incident_status' => IncidentStatus::Completed, 'incident_date' => $sameDay->copy()->setTime(14, 0)]));
        $done = Incident::withoutEvents(fn () => Incident::factory()->create(['incident_status' => IncidentStatus::Completed, 'incident_date' => $sameDay->copy()->setTime(16, 0)]));
        foreach ([$running, $monitoring, $done] as $incident) {
            $incident->pics()->attach($user);
        }
        ActionImprovement::factory()->create([
            'incident_id' => $monitoring->id,
            'status' => ActionImprovement::STATUS_ON_PROGRESS,
        ]);

        $graph = MyIncidents::contributionGraph($user);

        $today = collect($graph['weeks'])->pluck('days')->flatten(1)->firstWhere('date', $sameDay->format('Y-m-d'));
        $this->assertNotNull($today);
        $this->assertSame(1, $today['tier'], 'running outranks monitoring and done on the same date');
        $this->assertSame(3, $today['count']);
        $this->assertStringContainsString('In progress', $today['tooltip']);
    }

    public function test_graph_window_is_twelve_months_ending_today(): void
    {
        $user = User::factory()->create();

        $inWindow = Incident::factory()->create(['incident_date' => now()->subMonths(6)]);
        $outWindow = Incident::factory()->create(['incident_date' => now()->subMonths(13)]);
        $today = Incident::factory()->create(['incident_date' => now()->startOfDay()->addHours(9)]);
        foreach ([$inWindow, $outWindow, $today] as $incident) {
            $incident->pics()->attach($user);
        }

        $graph = MyIncidents::contributionGraph($user);

        $days = collect($graph['weeks'])->pluck('days')->flatten(1)->filter()->values();
        $dates = $days->pluck('date');

        $this->assertTrue($dates->contains(now()->format('Y-m-d')), 'today is the last cell');
        $this->assertFalse($dates->contains($outWindow->incident_date->format('Y-m-d')), '13 months ago is outside the window');
        $todayCell = $days->firstWhere('date', now()->format('Y-m-d'));
        $this->assertSame(1, $todayCell['count']);
        // Every week has exactly 7 day slots.
        foreach ($graph['weeks'] as $week) {
            $this->assertCount(7, $week['days']);
        }
    }

    public function test_graph_for_user_without_incidents_is_all_empty(): void
    {
        $user = User::factory()->create();

        $graph = MyIncidents::contributionGraph($user);

        $days = collect($graph['weeks'])->pluck('days')->flatten(1)->filter()->values();
        $this->assertGreaterThanOrEqual(300, $days->count());
        $this->assertTrue($days->every(fn ($day) => $day['tier'] === 0 && $day['count'] === 0));
    }
}
