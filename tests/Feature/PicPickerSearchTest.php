<?php

namespace Tests\Feature;

use App\Filament\Actions\ActionImprovementFormSchema;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PIC picker performance (owner 2026-11-19, after the Entra directory sync
 * brought 2.5k+ humans): the select must NOT preload every user as options —
 * it serves server-side search (top 50) over name/email/job title/team, and
 * still resolves a stored email to its label on the edit form.
 */
class PicPickerSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Creating an ActionImprovement notifies PICs — reverb is unresolvable
        // from host (same as ActionImprovementSearchApiTest).
        config(['broadcasting.default' => 'log']);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_search_returns_top_matches_across_name_email_title_and_team(): void
    {
        User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@dana.id']);
        User::factory()->create(['name' => 'Other Person', 'email' => 'x@dana.id', 'job_title' => 'Risk Analyst']);
        User::factory()->create(['name' => 'Team Player', 'email' => 't@dana.id', 'department' => 'Platform']);

        $field = $this->picField();

        $this->assertSame(['budi@dana.id' => 'Budi Santoso'], $field->getSearchResults('budi'));
        $this->assertSame(['x@dana.id' => 'Other Person'], $field->getSearchResults('Risk Analyst'));
        $this->assertSame(['t@dana.id' => 'Team Player'], $field->getSearchResults('Platform'));
    }

    public function test_search_caps_at_50_results(): void
    {
        User::factory()->count(60)->create(['name' => 'Zed Clone']);

        $this->assertCount(50, $this->picField()->getSearchResults('Zed Clone'));
    }

    public function test_stored_email_resolves_to_its_label(): void
    {
        User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@dana.id']);

        // The label closure (getOptionLabelUsing) is what the edit form calls
        // for every stored email — evaluate it directly with the value context.
        $field = $this->picField();
        $prop = (new \ReflectionClass($field))->getProperty('getOptionLabelUsing');
        $prop->setAccessible(true);
        $closure = $prop->getValue($field);

        $this->assertSame('Budi Santoso', $field->evaluate($closure, ['value' => 'budi@dana.id']));
        // Legacy email not in the directory (e.g. left the org) — still renders.
        $this->assertSame('gone@dana.id', $field->evaluate($closure, ['value' => 'gone@dana.id']));
    }

    private function picField(): \Filament\Forms\Components\Select
    {
        return collect(ActionImprovementFormSchema::schema())
            ->first(fn ($component) => $component instanceof \Filament\Forms\Components\Select && $component->getName() === 'pic_email');
    }
}
