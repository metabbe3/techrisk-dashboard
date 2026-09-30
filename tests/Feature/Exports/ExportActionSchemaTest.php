<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Enums\Severity;
use App\Filament\Actions\ExportActionSchema;
use App\Models\Incident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner rule (2026-09-30): every export preset permanently restricts
 * severity to METRIC_ELIGIBLE (P1–P4, X1–X4) — G and Non Incident rows
 * never reach an export, regardless of the optional filters.
 */
class ExportActionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_filters_excludes_non_metric_severities(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'G']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'Non Incident']);

        $severities = ExportActionSchema::applyFilters(Incident::query(), [])
            ->pluck('severity')->map(fn ($s) => $s->value);

        $this->assertSame(['P1'], $severities->all());
    }

    public function test_apply_filters_intersects_with_user_severity_pick(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'X1']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'G']);

        $severities = ExportActionSchema::applyFilters(Incident::query(), ['f_severity' => ['P1', 'G']])
            ->pluck('severity')->map(fn ($s) => $s->value);

        // G is picked by the user AND excluded by the rule — the rule wins.
        $this->assertSame(['P1'], $severities->all());
    }

    public function test_severity_options_only_offer_metric_eligible(): void
    {
        $severityField = $this->findFormField('f_severity');

        $this->assertNotNull($severityField);
        $this->assertSame(
            array_combine(Severity::METRIC_ELIGIBLE, Severity::METRIC_ELIGIBLE),
            $severityField->getOptions()
        );
    }

    public function test_quarter_filter_scopes_to_chosen_quarter(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-15']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-03-31']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-07-04']);

        $dates = $this->formattedIncidentDates(['f_quarter' => ['2026-Q1']]);

        $this->assertSame(['2026-01-15', '2026-03-31'], $dates);
    }

    public function test_quarter_filter_ors_multiple_quarters(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-15']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-07-04']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-10-31']);

        $dates = $this->formattedIncidentDates(['f_quarter' => ['2026-Q1', '2026-Q4']]);

        $this->assertSame(['2026-01-15', '2026-10-31'], $dates);
    }

    public function test_quarter_filter_stacks_with_severity(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-15']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'X1', 'incident_date' => '2026-02-01']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-07-04']);

        $dates = $this->formattedIncidentDates(['f_quarter' => ['2026-Q1'], 'f_severity' => ['P1']]);

        $this->assertSame(['2026-01-15'], $dates);
    }

    public function test_empty_quarter_filter_adds_no_constraint(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-01-15']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-07-04']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2025-12-31']);

        $this->assertSame(3, ExportActionSchema::applyFilters(Incident::query(), [])->count());
        $this->assertSame(3, ExportActionSchema::applyFilters(Incident::query(), ['f_quarter' => []])->count());
    }

    public function test_form_has_quarter_field_with_options_from_incident_date_range(): void
    {
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2025-08-01']);
        Incident::factory()->createQuietly(['classification' => 'Incident', 'severity' => 'P1', 'incident_date' => '2026-02-15']);

        $quarterField = $this->findFormField('f_quarter');

        $this->assertNotNull($quarterField);
        $this->assertSame([
            '2025-Q1' => 'Q1 2025', '2025-Q2' => 'Q2 2025', '2025-Q3' => 'Q3 2025', '2025-Q4' => 'Q4 2025',
            '2026-Q1' => 'Q1 2026', '2026-Q2' => 'Q2 2026', '2026-Q3' => 'Q3 2026', '2026-Q4' => 'Q4 2026',
        ], $quarterField->getOptions());
    }

    public function test_quarter_options_empty_when_no_incidents(): void
    {
        $quarterField = $this->findFormField('f_quarter');

        $this->assertNotNull($quarterField);
        $this->assertSame([], $quarterField->getOptions());
    }

    /** form() nests fields inside Sections — flatten to find one by name. */
    private function findFormField(string $name): ?object
    {
        $flatten = function (array $components) use (&$flatten): array {
            $out = [];
            foreach ($components as $component) {
                $out[] = $component;
                $out = array_merge($out, $flatten($component->getChildComponents()));
            }

            return $out;
        };

        return collect($flatten(ExportActionSchema::form()))
            ->first(fn ($field) => method_exists($field, 'getName') && $field->getName() === $name);
    }

    /** Deterministic ordered Y-m-d list after applyFilters(). */
    private function formattedIncidentDates(array $data): array
    {
        return ExportActionSchema::applyFilters(Incident::query(), $data)
            ->orderBy('incident_date')
            ->pluck('incident_date')
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->all();
    }
}
