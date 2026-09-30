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
        // form() nests fields inside Sections — flatten to find f_severity.
        $flatten = function (array $components) use (&$flatten): array {
            $out = [];
            foreach ($components as $component) {
                $out[] = $component;
                $out = array_merge($out, $flatten($component->getChildComponents()));
            }

            return $out;
        };

        $severityField = collect($flatten(ExportActionSchema::form()))
            ->first(fn ($field) => method_exists($field, 'getName') && $field->getName() === 'f_severity');

        $this->assertNotNull($severityField);
        $this->assertSame(
            array_combine(Severity::METRIC_ELIGIBLE, Severity::METRIC_ELIGIBLE),
            $severityField->getOptions()
        );
    }
}
