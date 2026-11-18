<?php

declare(strict_types=1);

namespace Tests\Feature\Notion;

use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Owner 2026-11-18: the incidents table's "Notion" column must not ride the
 * row's default record URL — Filament's ListRecords wraps every cell in an
 * anchor to the detail page when the table carries a ViewAction, so a record
 * WITHOUT an investigation page currently navigates to the incident detail.
 * Clicking the Notion cell must navigate NOWHERE without a page, and to the
 * Notion page only when one is linked.
 */
class NotionColumnLinkTest extends TestCase
{
    use RefreshDatabase;

    protected const PAGE_ID = 'aa11bb22cc33dd44ee55ff6600778899';

    public function test_the_notion_cell_without_a_page_is_not_a_link_to_the_detail_page(): void
    {
        $incident = $this->incident();

        $cell = $this->notionCellHtmlFor($incident);

        $this->assertStringNotContainsString('<a', $cell);
    }

    public function test_the_notion_cell_with_a_page_links_to_the_notion_page(): void
    {
        $incident = $this->incident(['notion_investigation_page_id' => self::PAGE_ID]);

        $cell = $this->notionCellHtmlFor($incident);

        $this->assertStringContainsString('https://www.notion.so/'.self::PAGE_ID, $cell);
    }

    private function incident(array $attributes = []): Incident
    {
        // The factory randomizes classification; pin a real incident.
        return Incident::factory()->create(array_merge([
            'classification' => \App\Enums\IncidentClassification::Incident->value,
        ], $attributes));
    }

    /** Extracts the rendered "Notion" cell of ONE record from the list HTML. */
    private function notionCellHtmlFor(Incident $incident): string
    {
        Permission::firstOrCreate(['name' => 'view incidents']);
        $user = User::factory()->create();
        $user->givePermissionTo(['view incidents']);

        // The list defers the table load (wire:init="loadTable") — the rows
        // only exist in the HTML after that call.
        $html = Livewire::actingAs($user)->test(ListIncidents::class)
            ->call('loadTable')
            ->html();

        preg_match(
            '/<td[^>]*record\.'.$incident->getKey().'\.column\.notion_investigation_link[^>]*>(.*?)<\/td>/s',
            $html,
            $m,
        );
        $this->assertArrayHasKey(1, $m, 'the Notion cell must render for the record');

        return $m[1];
    }
}
