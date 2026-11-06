<?php

namespace Tests\Feature\Notion;

use App\Filament\Pages\NotionSettings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notion\NotionInvestigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * PROJ-018: the "Investigation pages" parent ID is edited through the same
 * Change → Save/Cancel flow as the sync watch ID — read-only display by
 * default, Save disabled while empty or unchanged, an invalid value shows an
 * inline error and blocks the save, Cancel discards.
 */
class NotionInvestigationParentIdEditorTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_ID = '11111111222233334444555566667777';

    private const NEW_ID = 'abcdef0123456789abcdef0123456789';

    public function test_the_parent_id_is_shown_as_plain_text_and_is_not_editable_by_default(): void
    {
        $this->parentId(self::OLD_ID);

        $this->settingsPage()
            ->assertSee(self::OLD_ID)
            ->assertSee('Change')
            ->assertDontSee('New investigation parent page ID');
    }

    public function test_change_unlocks_the_field_with_the_current_id_prefilled(): void
    {
        $this->parentId(self::OLD_ID);

        $this->settingsPage()
            ->callFormComponentAction('changeInvestigationParentIdAction', 'changeInvestigationParentId')
            ->assertSet('editingInvestigationParentId', true)
            ->assertSet('data.investigation_parent_id_input', self::OLD_ID)
            ->assertSee('Save')
            ->assertSee('Cancel');
    }

    public function test_save_is_disabled_while_the_input_is_empty_or_unchanged(): void
    {
        $this->parentId(self::OLD_ID);

        $page = $this->settingsPage()
            ->callFormComponentAction('changeInvestigationParentIdAction', 'changeInvestigationParentId');

        $page->assertFormComponentActionDisabled('saveInvestigationParentIdAction', 'saveInvestigationParentId');

        $page->callFormComponentAction('saveInvestigationParentIdAction', 'saveInvestigationParentId');
        $this->assertSame(self::OLD_ID, $this->parentId());
        $page->assertSet('editingInvestigationParentId', true)->assertNotNotified();

        $page->set('data.investigation_parent_id_input', '')
            ->assertFormComponentActionDisabled('saveInvestigationParentIdAction', 'saveInvestigationParentId')
            ->callFormComponentAction('saveInvestigationParentIdAction', 'saveInvestigationParentId');
        $this->assertSame(self::OLD_ID, $this->parentId());
        $page->assertSet('editingInvestigationParentId', true);
    }

    public function test_saving_a_valid_new_id_persists_it_and_locks_the_field(): void
    {
        $this->parentId(self::OLD_ID);

        $this->settingsPage()
            ->callFormComponentAction('changeInvestigationParentIdAction', 'changeInvestigationParentId')
            ->set('data.investigation_parent_id_input', 'https://www.notion.so/workspace/'.self::NEW_ID.'?v=2')
            ->callFormComponentAction('saveInvestigationParentIdAction', 'saveInvestigationParentId')
            ->assertSet('editingInvestigationParentId', false)
            ->assertSet('investigationParentIdJustSaved', true)
            ->assertNotified('Notion settings saved')
            ->assertSee(self::NEW_ID)
            ->assertSee('✓ Saved');

        $this->assertSame(self::NEW_ID, $this->parentId());
    }

    public function test_save_rejects_a_value_that_has_no_notion_id(): void
    {
        $this->parentId(self::OLD_ID);

        $this->settingsPage()
            ->callFormComponentAction('changeInvestigationParentIdAction', 'changeInvestigationParentId')
            ->set('data.investigation_parent_id_input', 'notion page 42')
            ->callFormComponentAction('saveInvestigationParentIdAction', 'saveInvestigationParentId')
            ->assertSet('editingInvestigationParentId', true)
            ->assertSet('data.investigation_parent_id_input', 'notion page 42')
            ->assertSee('paste the page URL or its 32-character ID');

        $this->assertSame(self::OLD_ID, $this->parentId());
    }

    /** The ID is stored as pasted — dashes kept (owner 2026-11-04). */
    public function test_a_dashed_uuid_is_stored_as_pasted(): void
    {
        $this->parentId(self::OLD_ID);

        $this->settingsPage()
            ->callFormComponentAction('changeInvestigationParentIdAction', 'changeInvestigationParentId')
            ->set('data.investigation_parent_id_input', 'ce92556e-44dd-8256-9ec8-871ba6130e54')
            ->callFormComponentAction('saveInvestigationParentIdAction', 'saveInvestigationParentId')
            ->assertSet('editingInvestigationParentId', false)
            ->assertNotified('Notion settings saved');

        $this->assertSame('ce92556e-44dd-8256-9ec8-871ba6130e54', $this->parentId());
    }

    /**
     * A data source (a database's collection) is a valid parent too — the
     * created pages become its rows. Stored verbatim with the prefix; the
     * service routes it as parent.data_source_id.
     */
    public function test_a_data_source_id_is_accepted_and_stored_with_its_prefix(): void
    {
        $this->parentId(self::OLD_ID);

        $this->settingsPage()
            ->callFormComponentAction('changeInvestigationParentIdAction', 'changeInvestigationParentId')
            ->set('data.investigation_parent_id_input', 'collection://ce92556e-44dd-8256-9ec8-871ba6130e54')
            ->callFormComponentAction('saveInvestigationParentIdAction', 'saveInvestigationParentId')
            ->assertSet('editingInvestigationParentId', false)
            ->assertNotified('Notion settings saved');

        $this->assertSame('collection://ce92556e-44dd-8256-9ec8-871ba6130e54', $this->parentId());
    }

    public function test_cancel_discards_the_edit_without_saving(): void
    {
        $this->parentId(self::OLD_ID);

        $this->settingsPage()
            ->callFormComponentAction('changeInvestigationParentIdAction', 'changeInvestigationParentId')
            ->set('data.investigation_parent_id_input', 'notion page 42')
            ->callFormComponentAction('cancelInvestigationParentIdAction', 'cancelInvestigationParentId')
            ->assertSet('editingInvestigationParentId', false)
            ->assertDontSee('paste the page URL or its 32-character ID')
            ->assertSee(self::OLD_ID);

        $this->assertSame(self::OLD_ID, $this->parentId());
        $this->assertNull($this->settingsPage()->get('data.investigation_parent_id_input'));
    }

    /** Owner 2026-11-04 (round 12): the labels match the view section's naming. */
    public function test_the_settings_section_and_field_carry_the_round_12_labels(): void
    {
        $this->settingsPage()
            ->assertSee('Notion investigation pages')
            ->assertSee('Investigation page ID')
            ->assertDontSee('Investigation parent page ID');
    }

    private function parentId(?string $value = null): ?string
    {
        if (func_num_args() > 0) {
            Setting::set(NotionInvestigationService::PARENT_KEY, $value);

            return $value;
        }

        $stored = Setting::get(NotionInvestigationService::PARENT_KEY);

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
