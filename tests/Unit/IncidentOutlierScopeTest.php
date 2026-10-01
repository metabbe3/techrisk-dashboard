<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Incident;
use App\Models\Label;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Label "Outlier" (owner rule 2026-10-01): tagged incidents stay in every
 * count/list but are excluded from all MTBF/MTTR math. This is the shared
 * scope every metric query composes — the one-definition rule that
 * BUG-005/006/007 taught this repo the hard way.
 */
class IncidentOutlierScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_excludes_only_outlier_label_rows(): void
    {
        $outlier = Label::create(['name' => Label::OUTLIER]);
        $other = Label::create(['name' => 'Payment']);

        $a = Incident::factory()->createQuietly(['incident_date' => '2032-01-10 10:00']);
        $b = Incident::factory()->createQuietly(['incident_date' => '2032-01-20 10:00']);
        $c = Incident::factory()->createQuietly(['incident_date' => '2032-01-30 10:00']);
        $a->labels()->attach($outlier);
        $b->labels()->attach($other);

        $ids = Incident::query()->withoutOutliers()->pluck('id');

        $this->assertFalse($ids->contains($a->id), 'Outlier-tagged row must be excluded');
        $this->assertTrue($ids->contains($b->id), 'Other labels are not outliers');
        $this->assertTrue($ids->contains($c->id), 'Untagged rows pass through');
    }

    public function test_is_outlier_true_only_for_exact_label_name(): void
    {
        $lower = Label::create(['name' => 'outlier']); // near-name must NOT match
        $a = Incident::factory()->createQuietly();
        $a->labels()->attach($lower);

        $exact = Label::create(['name' => 'Outlier']);
        $b = Incident::factory()->createQuietly();
        $b->labels()->attach($exact);

        $this->assertFalse(Incident::find($a->id)->isOutlier());
        $this->assertTrue(Incident::find($b->id)->isOutlier());
    }
}
