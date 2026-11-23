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

    public function test_graph_defaults_to_the_current_calendar_year(): void
    {
        // Owner 2026-11-21: no rolling mode — the default window is the
        // current calendar year, Jan 1 – Dec 31.
        $user = User::factory()->create();

        $inYear = Incident::factory()->create(['incident_date' => now()->startOfYear()->addMonths(2)]);
        $lastYear = Incident::factory()->create(['incident_date' => now()->startOfYear()->subDay()]);
        $today = Incident::factory()->create(['incident_date' => now()->startOfDay()->addHours(9)]);
        foreach ([$inYear, $lastYear, $today] as $incident) {
            $incident->pics()->attach($user);
        }

        $graph = MyIncidents::contributionGraph($user);

        $days = collect($graph['weeks'])->pluck('days')->flatten(1)->filter()->values();
        $dates = $days->pluck('date');

        $this->assertTrue($dates->contains(now()->format('Y-m-d')), 'today is in the current-year window');
        // The leading week still RENDERS the last-year day (a grid cell), but
        // its incident must NOT leak into this year's data.
        $leak = $days->firstWhere('date', $lastYear->incident_date->format('Y-m-d'));
        $this->assertNotNull($leak, 'the leading week renders the previous-year day as a cell');
        $this->assertSame(0, $leak['count'], 'previous-year incidents stay out of the year count');
        $this->assertSame(2, $graph['total']);
        // Every week has exactly 7 day slots.
        foreach ($graph['weeks'] as $week) {
            $this->assertCount(7, $week['days']);
        }
    }

    public function test_years_lists_from_the_earliest_incident_year_through_now(): void
    {
        $user = User::factory()->create();
        $this->assertSame([(int) now()->year], MyIncidents::years($user), 'no incidents → current year only');

        $old = Incident::factory()->create(['incident_date' => now()->subYears(2)]);
        $old->pics()->attach($user);

        $years = MyIncidents::years($user);
        $this->assertSame((int) now()->subYears(2)->year, $years[0]);
        $this->assertSame((int) now()->year, $years[array_key_last($years)]);
    }

    public function test_graph_month_labels_are_complete_including_a_monday_first(): void
    {
        // Owner 2026-11-20: "keterangan bulan tidak lengkap". The old
        // pre-push check dropped the label whenever the 1st fell on a Monday
        // and OPENED a week (Jun 1 2026 is a Monday).
        Carbon::setTestNow(Carbon::create(2026, 11, 20, 12));
        $user = User::factory()->create();

        $graph = MyIncidents::contributionGraph($user);
        $months = collect($graph['weeks'])->pluck('month')->filter()->values()->all();

        $this->assertContains('Jun', $months, 'a month whose 1st opens a week must keep its label');
        $this->assertContains('Nov', $months, 'the leading partial month keeps its label too');
        // A 12-month window spans two Novembers (leading partial + final) —
        // so at least 12 labels, duplicates between YEARS are legitimate.
        $this->assertGreaterThanOrEqual(12, count($months));

        Carbon::setTestNow();
    }

    public function test_year_mode_covers_the_full_calendar_year(): void
    {
        $user = User::factory()->create();
        $incident = Incident::factory()->create(['incident_date' => Carbon::create(2025, 3, 15, 10)]);
        $incident->pics()->attach($user);

        $graph = MyIncidents::contributionGraph($user, 2025);

        $this->assertSame(1, $graph['total']);
        // The grid runs in whole Monday-first weeks, so the leading week starts
        // on the Monday at or before Jan 1 (Jan 1 2025 is a Wednesday).
        $first = collect($graph['weeks'])->pluck('days')->flatten(1)->first();
        $this->assertSame('2024-12-30', $first['date'], 'the grid starts on the Monday bounding Jan 1');
        $marchCell = collect($graph['weeks'])->pluck('days')->flatten(1)->firstWhere('date', '2025-03-15');
        $this->assertSame(1, $marchCell['tier']);
        // Dec 31 exists even though it is in the future relative to a 2025 view.
        $this->assertNotNull(collect($graph['weeks'])->pluck('days')->flatten(1)->firstWhere('date', '2025-12-31'));

        // The leading partial week (Mon Dec 30, 2024) must be labelled JANUARY
        // — its first cell belongs to 2024 but the window starts Jan 1, and
        // labelling it "Dec" silently dropped the year's first month.
        $months = collect($graph['weeks'])->pluck('month')->filter()->values()->all();
        $this->assertSame('Jan', $months[0]);
        $this->assertSame(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], $months);
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
