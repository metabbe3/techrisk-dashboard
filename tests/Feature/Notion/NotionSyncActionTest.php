<?php

namespace Tests\Feature\Notion;

use App\Filament\Actions\NotionSyncAction;
use App\Services\Notion\NotionIssueSyncService;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The shared sync runner: "Sync now" on both surfaces passes the full-sync
 * flag and the restore pick through to the service. Its form() is the
 * two-step Sync-now modal's schema (owner 2026-10-30 redesign): the
 * full-sync checkbox at step 1, the restore picker + its empty state at
 * step 2 — there is no Preview/dry-run modal anymore, the picker list IS
 * the preview.
 */
class NotionSyncActionTest extends TestCase
{
    public function test_run_defaults_to_a_normal_sync(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, false, null)
            ->andReturn(['status' => 'ok', 'message' => 'No new issues.', 'created' => 0, 'skipped' => 0, 'preview' => null]);

        NotionSyncAction::run();

        $this->assertSame('No new issues.', $this->sentToasts()->first()['title']);
    }

    public function test_run_passes_the_full_sync_flag_to_the_service(): void
    {
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, true, null)
            ->andReturn(['status' => 'ok', 'message' => 'Imported 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null]);

        NotionSyncAction::run(restoreDeleted: true);

        $this->assertSame('Imported 1 issue(s).', $this->sentToasts()->first()['title']);
    }

    public function test_run_passes_the_restore_pick_to_the_service(): void
    {
        // The picker's selection — even an empty one — is the operator's
        // explicit pick; only null (no picker involved) means restore-all.
        $this->mock(NotionIssueSyncService::class)
            ->shouldReceive('sync')->once()->with(false, true, ['aaaa556e44dd81c395b0c542f2599aaa'])
            ->andReturn(['status' => 'ok', 'message' => 'Imported 1 issue(s).', 'created' => 1, 'skipped' => 0, 'preview' => null]);

        NotionSyncAction::run(restoreDeleted: true, restorePageIds: ['aaaa556e44dd81c395b0c542f2599aaa']);

        $this->assertSame('Imported 1 issue(s).', $this->sentToasts()->first()['title']);
    }

    public function test_the_sync_modal_carries_the_full_sync_checkbox_and_the_restore_picker(): void
    {
        $form = NotionSyncAction::form();

        $this->assertCount(3, $form);

        // Step 1: the full-sync checkbox — live, so the submit label can
        // flip "Sync" → "Full sync" while it is ticked.
        $checkbox = $form[0];

        $this->assertInstanceOf(Checkbox::class, $checkbox);
        $this->assertSame('restoreDeleted', $checkbox->getName());
        $this->assertTrue($checkbox->isLive());

        // Step 2: the restore picker with Select All, plus its empty state.
        // Live is what activates the Import button in the browser — the
        // ticks must re-render the modal footer for its disabled state to
        // re-evaluate (prod 2026-10-30: without live the button stayed grey).
        $picker = $form[1];

        $this->assertInstanceOf(CheckboxList::class, $picker);
        $this->assertSame('restorePageIds', $picker->getName());
        $this->assertTrue($picker->isBulkToggleable());
        $this->assertTrue($picker->isLive());

        $empty = $form[2];

        $this->assertInstanceOf(Placeholder::class, $empty);
        $this->assertSame('restore_picker_empty', $empty->getName());
    }

    private function sentToasts(): Collection
    {
        return collect(session('filament.notifications', []));
    }
}
