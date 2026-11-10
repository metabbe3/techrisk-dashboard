<?php

namespace Tests\Unit\Services\Notion;

use App\Enums\IncidentClassification;
use App\Models\Incident;
use App\Services\Notion\NotionIssueMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Title dedupe must see the WHOLE table, not just Issues — the pre-sync
 * backlog was partly entered as Incidents (owner 2026-10-29), and the CSV
 * importer rides the same check as the Notion sync.
 */
class NotionIssueMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleans_notion_title_prefix(): void
    {
        $this->assertSame('DB down', NotionIssueMapper::cleanTitle('Summary of Incident - DB down'));
        $this->assertSame('DB down', NotionIssueMapper::cleanTitle('DB down'));
    }

    /**
     * @dataProvider severityMappings
     */
    public function test_maps_notion_classifications_to_severity(string $classification, string $expected): void
    {
        $this->assertSame($expected, NotionIssueMapper::mapSeverityToCode($classification));
    }

    public static function severityMappings(): array
    {
        return [
            'deployment' => ['Deployment Issues', 'P1'],
            'infrastructure' => ['Infrastructure Issue', 'P1'],
            'code bug' => ['Code Bug', 'P2'],
            'configuration' => ['Configuration Error', 'P2'],
            'data' => ['Data Issue', 'P3'],
            'ui' => ['UI Issue', 'P4'],
            'internal' => ['Internal Improving Issue', 'G'],
            'unknown falls back to G' => ['Something New', 'G'],
        ];
    }

    public function test_parses_notion_date_format_and_fallback(): void
    {
        $this->assertSame('2026-01-12', NotionIssueMapper::parseNotionDate('January 12, 2026')->toDateString());
        $this->assertSame('2026-01-12', NotionIssueMapper::parseNotionDate('2026-01-12')->toDateString());
    }

    public function test_generate_issue_id_has_expected_shape_and_is_unique(): void
    {
        $first = NotionIssueMapper::generateIssueId();
        $second = NotionIssueMapper::generateIssueId();

        $this->assertMatchesRegularExpression('/^\d{8}_IS_\d{4}$/', $first);
        $this->assertNotSame($first, $second);
    }

    /**
     * @dataProvider classificationSeverities
     */
    public function test_maps_classification_column_to_severity(?string $classification, ?string $expected): void
    {
        $this->assertSame($expected, NotionIssueMapper::mapClassificationToSeverity($classification));
    }

    public static function classificationSeverities(): array
    {
        return [
            'P1' => ['P1', 'P1'],
            'X4' => ['X4', 'X4'],
            'padded' => [' P2 ', 'P2'],
            'unclassified dash' => ['-', null],
            'null' => [null, null],
            'not a severity' => ['Deployment Issues', null],
            'trailing digit out of range' => ['P5', null],
        ];
    }

    private function incident(string $classification): Incident
    {
        return Incident::create([
            'title' => 'DB down',
            'no' => '20260101_IS_1111',
            'incident_date' => '2026-01-10',
            'entry_date_tech_risk' => '2026-01-10',
            'severity' => 'P2',
            'classification' => $classification,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);
    }

    public function test_find_duplicate_by_title_matches_an_incident_classified_row(): void
    {
        $incident = $this->incident(IncidentClassification::Incident->value);

        $this->assertSame($incident->id, NotionIssueMapper::findDuplicateByTitle('Summary of Incident - DB DOWN')?->id);
    }

    public function test_existing_normalized_titles_includes_incident_rows(): void
    {
        $this->incident(IncidentClassification::Incident->value);

        $this->assertArrayHasKey('db down', NotionIssueMapper::existingNormalizedTitles());
    }

    /**
     * Owner 2026-11-09: issues imported from Notion carry their date in the
     * title — "2026 Nov 09 - timeout to database" (the row's own date_start,
     * falling back to createdTime — the same date importRow resolves).
     */
    public function test_dated_title_carries_the_y_m_d_date_prefix(): void
    {
        $this->assertSame(
            '2026 Nov 09 - timeout to database',
            NotionIssueMapper::datedTitle('timeout to database', Carbon::parse('2026-11-09'))
        );
        // The Notion "Summary of Incident - " prefix cleans first…
        $this->assertSame(
            '2026 Nov 09 - DB down',
            NotionIssueMapper::datedTitle('Summary of Incident - DB down', Carbon::parse('2026-11-09'))
        );
        // …and a title already carrying the sync's prefix is never doubled.
        $this->assertSame(
            '2026 Nov 09 - DB down',
            NotionIssueMapper::datedTitle('2026 Nov 09 - DB down', Carbon::parse('2026-11-09'))
        );
    }

    /**
     * The dedupe net must still catch a re-synced raw name behind a stored
     * prefixed title — comparison strips the sync's own prefix from BOTH
     * sides (page-ID dedupe stays the primary net; this is the name net).
     */
    public function test_title_dedupe_sees_through_the_date_prefix(): void
    {
        $incident = Incident::create([
            'title' => '2026 Nov 09 - timeout to database',
            'no' => '20261109_IS_1111',
            'incident_date' => '2026-11-09',
            'entry_date_tech_risk' => '2026-11-09',
            'severity' => 'P3',
            'classification' => IncidentClassification::Issue->value,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);

        $this->assertSame($incident->id, NotionIssueMapper::findDuplicateByTitle('timeout to database')?->id);
        $this->assertArrayHasKey('timeout to database', NotionIssueMapper::existingNormalizedTitles());
    }

    /**
     * The live bug (2026-11-09): the Notion row's own name already carries a
     * "9 November 2026 - " prefix (195/300 record titles, round 7) — the
     * composed title carries the date ONCE.
     */
    public function test_dated_title_strips_the_name_s_own_date_prefix(): void
    {
        $this->assertSame(
            '2026 Nov 09 - Multiple App Error Top Spiking',
            NotionIssueMapper::datedTitle('9 November 2026 - Multiple App Error Top Spiking', Carbon::parse('2026-11-09'))
        );
    }

    /**
     * Comparison strips ANY leading date prefix — the sync's own format, the
     * record's own "DD Month YYYY - " style, or both stacked (a title
     * imported before the own-prefix fix). A title that is only a date keeps
     * it; a real title that merely mentions a date keeps its mention.
     */
    public function test_strip_date_prefix_sees_through_both_prefix_kinds(): void
    {
        $this->assertSame('DB down', NotionIssueMapper::stripDatePrefix('2026 Nov 09 - DB down'));
        $this->assertSame('DB down', NotionIssueMapper::stripDatePrefix('9 November 2026 - DB down'));
        $this->assertSame('DB down', NotionIssueMapper::stripDatePrefix('2026-11-09 - DB down'));
        $this->assertSame('DB down', NotionIssueMapper::stripDatePrefix('November 9, 2026 - DB down'));
        $this->assertSame('DB down', NotionIssueMapper::stripDatePrefix('2026 Nov 09 - 9 November 2026 - DB down'));
        $this->assertSame('9 November 2026', NotionIssueMapper::stripDatePrefix('9 November 2026'));
        $this->assertSame('Week of 2026-11-09 review', NotionIssueMapper::stripDatePrefix('Week of 2026-11-09 review'));
    }

    /**
     * A row imported before the own-prefix fix carries BOTH prefixes — it
     * still dedupes against the raw Notion name.
     */
    public function test_title_dedupe_sees_through_a_doubled_date_prefix(): void
    {
        $incident = Incident::create([
            'title' => '2026 Nov 09 - 9 November 2026 - Multiple App Error Top Spiking',
            'no' => '20261109_IS_1112',
            'incident_date' => '2026-11-09',
            'entry_date_tech_risk' => '2026-11-09',
            'severity' => 'G',
            'classification' => IncidentClassification::Issue->value,
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
        ]);

        $this->assertSame(
            $incident->id,
            NotionIssueMapper::findDuplicateByTitle('9 November 2026 - Multiple App Error Top Spiking')?->id
        );
    }
}
