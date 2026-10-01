<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\GroupedIncidentsExport;
use App\Exports\Sheets\PerCategorySheet;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * BUG-022: non-JSON grouping dimensions (severity / pic / incident_type)
 * read an undefined $column inside the row-filter closure — web context
 * converts the warning to a 500, and even where tolerated every group
 * came back empty (only the Summary sheet was produced).
 *
 * Also pins the owner rule (2026-09-29): severity grouping is P1–P4 and
 * X1–X4 only — G and Non Incident never get sheets.
 */
class GroupedIncidentsExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_severity_dimension_groups_metric_eligible_only(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_status' => 'Completed', 'fund_loss' => 100]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_status' => 'Completed', 'fund_loss' => 200]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'X1', 'incident_status' => 'Completed', 'fund_loss' => 300]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'G', 'fund_loss' => 999]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'Non Incident', 'fund_loss' => 888]);

        $sheets = (new GroupedIncidentsExport(Incident::query(), 'severity'))->sheets();

        $titles = array_map(fn ($sheet) => $sheet->title(), $sheets);
        $this->assertSame(['Summary', 'P1', 'P2', 'X1'], $titles);

        // One by one: each group sheet holds exactly its own rows.
        $counts = [];
        foreach ($sheets as $sheet) {
            if ($sheet instanceof PerCategorySheet) {
                $counts[$sheet->title()] = $sheet->query()->count();
            }
        }
        $this->assertSame(['P1' => 1, 'P2' => 1, 'X1' => 1], $counts);

        // Summary rows match the groups (label + count + actual fund).
        $summaryRows = $sheets[0]->collection();
        $this->assertSame(3, $summaryRows->count());
        $this->assertSame(1, $summaryRows->firstWhere('label', 'P1')['count']);
        $this->assertSame(100.0, $summaryRows->firstWhere('label', 'P1')['actual']);
        $this->assertSame(300.0, $summaryRows->firstWhere('label', 'X1')['actual']);
    }

    public function test_incident_type_dimension_creates_group_sheets(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_type' => 'Tech']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_type' => 'Non-tech']);

        $sheets = (new GroupedIncidentsExport(Incident::query(), 'incident_type'))->sheets();

        $titles = array_map(fn ($sheet) => $sheet->title(), $sheets);
        // pluck()->unique()->sort() orders alphabetically.
        $this->assertSame(['Summary', 'Non-tech', 'Tech'], $titles);

        foreach ($sheets as $sheet) {
            if ($sheet instanceof PerCategorySheet) {
                $this->assertSame(1, $sheet->query()->count(), $sheet->title());
            }
        }
    }

    public function test_pic_dimension_filters_by_id_not_name_lookup(): void
    {
        // Two PICs with the SAME name: array_search(label, names) resolved
        // both groups to the first user's id, so both sheets held user 1's
        // rows and user 2's incidents vanished from the export.
        $u1 = User::factory()->create(['name' => 'Andi']);
        $u2 = User::factory()->create(['name' => 'Andi']);
        Notification::fake(); // pivot attach fires assignment notifications — setup noise here
        $i1 = Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'fund_loss' => 10]);
        $i1->pics()->attach($u1->id);
        $i2 = Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'fund_loss' => 20]);
        $i2->pics()->attach($u2->id);

        $sheets = (new GroupedIncidentsExport(Incident::query(), 'pic'))->sheets();

        $perSheets = array_values(array_filter($sheets, fn ($sheet) => $sheet instanceof PerCategorySheet));
        $this->assertCount(2, $perSheets);

        $seenIds = [];
        foreach ($perSheets as $sheet) {
            $rows = $sheet->query()->get();
            $this->assertCount(1, $rows, 'each PIC sheet holds exactly one incident');
            $seenIds[] = $rows->first()->id;
        }
        sort($seenIds);
        $expected = [$i1->id, $i2->id];
        sort($expected);
        $this->assertSame($expected, $seenIds);
    }

    public function test_quarter_dimension_creates_sheet_per_quarter_across_years(): void
    {
        // Explicit dates: the factory defaults incident_date to a random
        // dateTimeThisYear() which would make quarters non-deterministic.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2025-01-15 10:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2026-01-20 09:00']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P3', 'incident_date' => '2026-04-10 08:00']);
        // Exact quarter boundaries: last hour of Q1, first hour of Q2.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-03-31 23:30']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2026-04-01 00:30']);

        $sheets = (new GroupedIncidentsExport(Incident::query(), 'quarter'))->sheets();

        $titles = array_map(fn ($sheet) => $sheet->title(), $sheets);
        // String sort on "YYYY-Qn" is chronological; the same quarter in two
        // years stays two sheets ("Q1 2025" vs "Q1 2026").
        $this->assertSame(['Summary', 'Q1 2025', 'Q1 2026', 'Q2 2026'], $titles);

        $counts = [];
        foreach ($sheets as $sheet) {
            if ($sheet instanceof PerCategorySheet) {
                $counts[$sheet->title()] = $sheet->query()->count();
            }
        }
        // Boundary rows land in their own quarter (whereBetween is inclusive).
        $this->assertSame(['Q1 2025' => 1, 'Q1 2026' => 2, 'Q2 2026' => 2], $counts);
    }

    public function test_quarter_summary_stats(): void
    {
        // incident_date is NOT NULL at the schema level, so a dateless row
        // cannot exist — no null-date exclusion case to pin here.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-01 10:00', 'incident_status' => 'Completed', 'fund_loss' => 100]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2026-02-10 10:00', 'incident_status' => 'Completed', 'fund_loss' => 50]);

        $sheets = (new GroupedIncidentsExport(Incident::query(), 'quarter'))->sheets();

        $this->assertSame(['Summary', 'Q1 2026'], array_map(fn ($s) => $s->title(), $sheets));

        $summary = $sheets[0]->collection();
        $this->assertSame(1, $summary->count());
        $row = $summary->firstWhere('label', 'Q1 2026');
        $this->assertSame(2, $row['count']);
        $this->assertSame(150.0, $row['actual']);
        $this->assertSame(2, $sheets[1]->query()->count());
    }

    public function test_summary_is_widget_aligned_and_excluded_rows_get_tabs(): void
    {
        // Owner rule (2026-09-30): Summary Cases/Actual/Potential follow the
        // dashboard widget rules; rows with excluded fund statuses move to
        // their own "Excluded - ..." tabs instead of being silently dropped.
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-01 10:00', 'fund_status' => 'Non fundLoss', 'incident_status' => 'Completed', 'fund_loss' => 100]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2026-02-02 10:00', 'fund_status' => 'Non fundLoss', 'incident_status' => 'In progress', 'potential_fund_loss' => 200]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-03 10:00', 'fund_status' => 'Potential recovery', 'incident_status' => 'Completed', 'fund_loss' => 50]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'incident_date' => '2026-02-04 10:00', 'fund_status' => 'Fully recovered', 'incident_status' => 'Completed', 'fund_loss' => 30, 'recovered_fund' => 80]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-05 10:00', 'fund_status' => 'Non Tech Loss', 'incident_status' => 'In progress']);

        $sheets = (new GroupedIncidentsExport(Incident::query(), 'quarter'))->sheets();

        $titles = array_map(fn ($s) => $s->title(), $sheets);
        // Group sheet lists every eligible row; the 3 excluded fund statuses
        // each get their own tab.
        $this->assertSame(
            ['Summary', 'Q1 2026', 'Excluded - Potential Recovery', 'Excluded - Fully Recovered', 'Excluded - Non Tech Loss'],
            $titles
        );

        // Summary row = widget rules, not raw sums.
        $row = $sheets[0]->collection()->firstWhere('label', 'Q1 2026');
        $this->assertSame(2, $row['count']);            // excludes the 3 excluded-status rows (aiCounts rule)
        $this->assertSame(100.0, $row['actual']);      // Completed + fund-status-excluded (Fund Loss card), not 100+50+30
        $this->assertSame(200.0, $row['potential']);   // open cases only (Potential Fund Loss card)
        $this->assertSame(80.0, $row['recovered']);    // Recovered card has no fund-status exclusion

        // Excluded tabs hold exactly their own rows; group sheet holds all 5.
        $this->assertSame(5, $sheets[1]->query()->count());
        $this->assertSame(1, $sheets[2]->query()->count());
        $this->assertSame(1, $sheets[3]->query()->count());
        $this->assertSame(1, $sheets[4]->query()->count());
    }
}
