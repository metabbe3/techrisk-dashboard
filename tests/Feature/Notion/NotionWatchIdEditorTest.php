<?php

namespace Tests\Feature\Notion;

use App\Filament\Pages\NotionSettings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notion\NotionIssueSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Owner 2026-10-30: the "Notion page or database ID" field is never open for
 * direct editing — the user must click "Change", enter the new ID, then
 * confirm. Read-only display by default, Save disabled while the input is
 * empty or unchanged, Cancel discards without saving, an invalid ID shows an
 * inline error and blocks the save, and a saved change shows a confirmation
 * state on the field.
 */
class NotionWatchIdEditorTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_ID = '11111111222233334444555566667777';

    private const NEW_ID = 'abcdef0123456789abcdef0123456789';

    public function test_the_watch_id_is_shown_as_plain_text_and_is_not_editable_by_default(): void
    {
        $this->watchId(self::OLD_ID);

        $this->settingsPage()
            ->assertSee(self::OLD_ID)
            ->assertSee('Change')
            // Story 1: no editable input on page load — the edit input and
            // its label do not render (Filament emits an empty hidden
            // wrapper), and the old always-on "Save Settings" button is gone.
            ->assertDontSee('New Notion page or database ID')
            ->assertDontSee('Save Settings');
    }

    public function test_change_sync_now_and_preview_share_one_button_row(): void
    {
        // Owner 2026-10-30: Change, Sync now, and Preview sit on the same
        // horizontal line — one Filament Actions row, not two stacked rows.
        // PROJ-018 added a second row for the "Investigation pages" section;
        // this section's row must stay a single one (a split here makes it 3
        // and still fails).
        $this->assertSame(
            2,
            substr_count($this->settingsPage()->html(), 'fi-fo-actions'),
        );
    }

    public function test_change_unlocks_the_field_with_the_current_id_prefilled(): void
    {
        $this->watchId(self::OLD_ID);

        $this->settingsPage()
            ->callFormComponentAction('changeWatchIdAction', 'changeWatchId')
            ->assertSet('editingWatchId', true)
            ->assertSet('data.watch_id_input', self::OLD_ID)
            ->assertSee('Save')
            ->assertSee('Cancel');
    }

    public function test_save_is_disabled_while_the_input_is_empty_or_unchanged(): void
    {
        $this->watchId(self::OLD_ID);

        // Unchanged (the pre-filled value): the button renders disabled and
        // the server-side guard saves nothing.
        $page = $this->settingsPage()
            ->callFormComponentAction('changeWatchIdAction', 'changeWatchId');

        $page->assertFormComponentActionDisabled('saveWatchIdAction', 'saveWatchId');

        $page->callFormComponentAction('saveWatchIdAction', 'saveWatchId');
        $this->assertSame(self::OLD_ID, $this->watchId());
        $page->assertSet('editingWatchId', true)->assertNotNotified();

        // Empty: same.
        $page->set('data.watch_id_input', '')
            ->assertFormComponentActionDisabled('saveWatchIdAction', 'saveWatchId')
            ->callFormComponentAction('saveWatchIdAction', 'saveWatchId');
        $this->assertSame(self::OLD_ID, $this->watchId());
        $page->assertSet('editingWatchId', true);
    }

    public function test_saving_a_valid_new_id_persists_it_and_locks_the_field(): void
    {
        $this->watchId(self::OLD_ID);

        $this->settingsPage()
            ->callFormComponentAction('changeWatchIdAction', 'changeWatchId')
            ->set('data.watch_id_input', 'https://www.notion.so/workspace/'.self::NEW_ID.'?v=2')
            ->callFormComponentAction('saveWatchIdAction', 'saveWatchId')
            ->assertSet('editingWatchId', false)
            ->assertSet('watchIdJustSaved', true)
            ->assertNotified('Notion settings saved')
            // Story 3 + 5: read-only view is back, shows the new ID with a
            // confirmation state on the field.
            ->assertSee(self::NEW_ID)
            ->assertSee('✓ Saved');

        $this->assertSame(self::NEW_ID, $this->watchId());
    }

    public function test_save_rejects_a_value_that_has_no_notion_id(): void
    {
        $this->watchId(self::OLD_ID);

        $this->settingsPage()
            ->callFormComponentAction('changeWatchIdAction', 'changeWatchId')
            ->set('data.watch_id_input', 'notion page 42')
            ->callFormComponentAction('saveWatchIdAction', 'saveWatchId')
            // Story 5: inline error, edit mode kept, input intact, nothing saved.
            ->assertSet('editingWatchId', true)
            ->assertSet('data.watch_id_input', 'notion page 42')
            ->assertSee('paste the page URL or its 32-character ID');

        $this->assertSame(self::OLD_ID, $this->watchId());
    }

    public function test_cancel_discards_the_edit_without_saving(): void
    {
        $this->watchId(self::OLD_ID);

        $this->settingsPage()
            ->callFormComponentAction('changeWatchIdAction', 'changeWatchId')
            ->set('data.watch_id_input', 'notion page 42')
            ->callFormComponentAction('cancelWatchIdAction', 'cancelWatchId')
            ->assertSet('editingWatchId', false)
            ->assertDontSee('paste the page URL or its 32-character ID')
            ->assertSee(self::OLD_ID);

        $this->assertSame(self::OLD_ID, $this->watchId());
        $this->assertNull($this->settingsPage()->get('data.watch_id_input'));
    }

    private function watchId(?string $value = null): ?string
    {
        if (func_num_args() > 0) {
            Setting::set(NotionIssueSyncService::WATCH_KEY, $value);

            return $value;
        }

        $stored = Setting::get(NotionIssueSyncService::WATCH_KEY);

        return $stored === null ? null : (string) $stored;
    }

    private function settingsPage()
    {
        Permission::firstOrCreate(['name' => 'manage api tokens']);

        $user = User::factory()->create();
        $user->givePermissionTo('manage api tokens');

        return Livewire::actingAs($user)->test(NotionSettings::class);
    }
}
