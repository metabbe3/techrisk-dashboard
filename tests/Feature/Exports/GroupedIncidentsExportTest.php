<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Exports\GroupedIncidentsExport;
use App\Exports\Sheets\PerCategorySheet;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'fund_loss' => 100]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'fund_loss' => 200]);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'X1', 'fund_loss' => 300]);
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
        $i1 = Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'pic_id' => $u1->id, 'fund_loss' => 10]);
        $i2 = Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P2', 'pic_id' => $u2->id, 'fund_loss' => 20]);

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
}
