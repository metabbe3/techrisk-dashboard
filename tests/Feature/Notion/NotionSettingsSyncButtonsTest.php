<?php

namespace Tests\Feature\Notion;

use App\Filament\Actions\NotionSyncAction;
use App\Filament\Pages\NotionSettings;
use App\Models\User;
use App\Services\Notion\NotionIssueSyncService;
use Filament\Actions\StaticAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The Notion Settings page's sync buttons live INSIDE the "Sync issue from
 * Notion" section (owner 2026-10-29), not in the page header. "Sync now" is
 * a two-step modal (owner 2026-10-30 redesign): step 1 is the full-sync
 * checkbox with a submit button labelled "Sync" — "Full sync" once ticked;
 * clicking it reads Notion and turns the same modal into the restore picker
 * (Select All included), where the submit becomes "Import" — greyed out
 * until at least one issue is selected. There is no Preview/dry-run step
 * anymore: the picker list IS the preview. They are form-component actions —
 * the header holds only the connection buttons.
 */
class NotionSettingsSyncButtonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_now_defaults_to_a_normal_sync(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('restorableIssues')->andReturn([])
            ->shouldReceive('sync')->once()->with(false, false, null)
            ->andReturn(['status' => 'ok', 'message' => 'No new issues.', 'created' => 0, 'skipped' => 0, 'preview' => null]);

        $this->settingsPage()
            ->callFormComponentAction('syncNowAction', 'syncNow')
            ->assertNotified('No new issues.');
    }

    public function test_the_submit_button_is_sync_until_the_full_sync_checkbox_is_ticked(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('restorableIssues')->andReturn([]);

        $page = $this->settingsPage()
            ->mountFormComponentAction('syncNowAction', 'syncNow');

        $this->assertSame('Sync', $this->submitLabel($page));

        $page->setFormComponentActionData(['restoreDeleted' => true]);

        $this->assertSame('Full sync', $this->submitLabel($page));
    }

    public function test_the_full_sync_button_reads_notion_and_opens_the_restore_picker(): void
    {
        $this->mock(NotionIssueSyncService::class)
            // The full-sync click reads Notion — exactly once, fresh (the
            // click drops the options memo).
            ->shouldReceive('restorableIssues')->once()->andReturn([
                'aaaa556e44dd81c395b0c542f2599aaa' => 'DB down (2026-01-12, P2)',
            ])
            // Opening the picker never syncs by itself — only Import does.
            ->shouldReceive('sync')->never();

        $page = $this->settingsPage()
            ->callFormComponentAction('syncNowAction', 'syncNow', data: ['restoreDeleted' => true]);

        $page
            ->assertFormComponentActionMounted('syncNowAction', ['syncNow'])
            ->assertSee('Deleted issues to restore')
            ->assertSee('DB down (2026-01-12, P2)')
            ->assertSee('Cancel');

        $this->assertTrue($page->instance()->fullSyncPickerOpen);
        $this->assertSame('Import', $this->submitLabel($page));
    }

    public function test_import_is_greyed_out_until_an_issue_is_selected(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('restorableIssues')->andReturn([
                'aaaa556e44dd81c395b0c542f2599aaa' => 'DB down (2026-01-12, P2)',
            ]);

        $page = $this->settingsPage()
            ->callFormComponentAction('syncNowAction', 'syncNow', data: ['restoreDeleted' => true]);

        $this->assertTrue($this->submitAction($page)->isDisabled());

        $page->setFormComponentActionData(['restorePageIds' => ['aaaa556e44dd81c395b0c542f2599aaa']]);

        $this->assertFalse($this->submitAction($page)->isDisabled());
    }

    public function test_import_restores_only_the_picked_issues(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('restorableIssues')->andReturn([
                'aaaa556e44dd81c395b0c542f2599aaa' => 'DB down (2026-01-12, P2)',
                'bbbb556e44dd81c395b0c542f2599bbb' => 'API down (2026-02-02, P1)',
            ])
            ->shouldReceive('sync')->once()->with(false, true, ['aaaa556e44dd81c395b0c542f2599aaa'])
            ->andReturn(['status' => 'ok', 'message' => 'Imported 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null]);

        $this->settingsPage()
            // Step 1 → 2: tick full sync, click "Full sync" — the picker opens.
            ->callFormComponentAction('syncNowAction', 'syncNow', data: ['restoreDeleted' => true])
            ->assertFormComponentActionMounted('syncNowAction', ['syncNow'])
            // Step 2: pick one of the two, click "Import" (still mounted —
            // no re-mount, or the nesting index would grow).
            ->setFormComponentActionData(['restorePageIds' => ['aaaa556e44dd81c395b0c542f2599aaa']])
            ->call('callMountedFormComponentAction')
            ->assertNotified('Imported 1 issue(s).');
    }

    public function test_reopening_the_sync_now_modal_starts_from_step_1_again(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('restorableIssues')->andReturn([
                'aaaa556e44dd81c395b0c542f2599aaa' => 'DB down (2026-01-12, P2)',
            ]);

        $page = $this->settingsPage()
            ->callFormComponentAction('syncNowAction', 'syncNow', data: ['restoreDeleted' => true]);

        $this->assertTrue($page->instance()->fullSyncPickerOpen);

        // The X / Cancel closes the modal; the next "Sync now" opens step 1.
        $page
            ->call('unmountFormComponentAction', false, false)
            ->call('mountFormComponentAction', 'data.syncNowAction', 'syncNow');

        $this->assertFalse($page->instance()->fullSyncPickerOpen);
        $this->assertSame('Sync', $this->submitLabel($page));
    }

    public function test_the_full_sync_picker_carries_a_select_all_toggle(): void
    {
        $picker = NotionSyncAction::form()[1];

        $this->assertTrue($picker->isBulkToggleable());
    }

    public function test_the_sync_buttons_are_not_page_header_actions(): void
    {
        $this->settingsPage()
            ->assertActionDoesNotExist('syncNow')
            ->assertActionDoesNotExist('previewSync');
    }

    /**
     * The modal footer's submit button, freshly resolved from the current
     * Livewire state (the label/disabled closures re-evaluate per render).
     */
    private function submitAction($page): StaticAction
    {
        $submit = collect($page->instance()->getMountedFormComponentAction()->getVisibleModalFooterActions())
            ->first(fn (StaticAction $action): bool => $action->getName() === 'submit');

        $this->assertNotNull($submit);

        return $submit;
    }

    private function submitLabel($page): string
    {
        return $page->instance()->getMountedFormComponentAction()->getModalSubmitActionLabel();
    }

    private function settingsPage()
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $user = User::factory()->create();
        $user->givePermissionTo('manage api tokens');

        return Livewire::actingAs($user)->test(NotionSettings::class);
    }
}
