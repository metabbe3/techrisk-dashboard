<?php

namespace Tests\Feature\Filament;

use App\Enums\IncidentClassification;
use App\Filament\Resources\IssueResource\Pages\ViewIssue;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Issue view page shows the Notion page-body sections (filled by the
 * sync / NotionPageBodyParser) and hides the optional ones when empty.
 */
class ViewIssueContentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::firstOrCreate(['name' => 'admin']);
        Permission::firstOrCreate(['name' => 'view issues']);
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->assignRole('admin'); // skips applyUserYearAccess record scoping
        $user->givePermissionTo(['view issues', 'view incidents']);

        return $user;
    }

    private function issue(array $overrides = []): Incident
    {
        return Incident::factory()->create(array_merge([
            'classification' => IncidentClassification::Issue,
            'summary' => 'Leading summary paragraph.',
            'root_cause' => 'Dead database.',
            'timeline' => '15:00 → DB down',
            'improvements' => '- Restarted MySQL',
        ], $overrides));
    }

    public function test_shows_all_content_sections_when_filled(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ViewIssue::class, ['record' => (string) $this->issue()->id])
            ->assertSuccessful()
            ->assertSeeText('Summary')
            ->assertSeeText('Root Cause')
            ->assertSeeText('Incident Timeline & Chronology')
            ->assertSeeText('Action Taken')
            ->assertSeeText('Dead database.')
            ->assertSeeText('15:00 → DB down')
            ->assertSeeText('Restarted MySQL');
    }

    public function test_hides_optional_sections_when_content_is_missing(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ViewIssue::class, ['record' => (string) $this->issue([
                'root_cause' => null,
                'timeline' => null,
                'improvements' => null,
            ])->id])
            ->assertSuccessful()
            ->assertSeeText('Summary')
            ->assertDontSeeText('Root Cause Analysis')  // section hidden, only the placeholder-free fields remain
            ->assertDontSeeText('Restarted MySQL');
    }

    public function test_each_timeline_entry_renders_on_its_own_line(): void
    {
        // Markdown collapses single newlines — the stored "\n" between
        // "HH:MM →" entries must become a hard break (<br>) or they run on.
        $timeline = "23:55 → CIMB started their API public key updates\n00:37 → CIMB informed the key rotation impact\n03:30 → Zoom call dismissed";

        $rendered = Livewire::actingAs($this->admin())
            ->test(ViewIssue::class, ['record' => (string) $this->issue(['timeline' => $timeline])->id])
            ->assertSuccessful()
            ->html();

        $this->assertStringContainsString('23:55 → CIMB started', $rendered);
        $this->assertStringContainsString('<br', $rendered);
        // And the run-on mush never appears in the rendered output.
        $this->assertStringNotContainsString('updates 00:37', strip_tags($rendered));
    }
}
