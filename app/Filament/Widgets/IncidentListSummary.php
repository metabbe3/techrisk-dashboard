<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Statistics\IncidentStatsFooterData;
use Filament\Widgets\Widget;

/**
 * Summary block on the Incidents list page (owner 2026-11-17): moved OUT of
 * the table's contentFooter — it rode the table's horizontal scroll container
 * — onto the page as a header widget, so it stays put while the table
 * scrolls and sits above the tabs. The view (livewire.incident-stats-footer)
 * renders it as a collapsible section.
 */
class IncidentListSummary extends Widget
{
    protected static string $view = 'livewire.incident-stats-footer';

    protected int|string|array $columnSpan = 'full';

    // Filament widgets are lazy by default — the content would mount in a
    // separate /livewire/update request where request('tableFilters') is
    // empty, so IncidentStatsFooterData would lose the date-filter mirroring
    // (BUG-018). Eager = rendered inline by the page request, exactly like
    // the contentFooter it replaced.
    protected static bool $isLazy = false;

    protected function getViewData(): array
    {
        // BUG-022 (merged from main 2026-11-18): read the SAME rows the
        // summary sits above — tab + filters + search — not just the
        // request-derived date filters. Callers with no table context
        // (exports) fall back to build()'s own base query.
        //
        // Livewire v3 has no $this->getLivewire() on a component (that
        // method lives on Filament's Table concerns only). This widget is
        // EAGER ($isLazy = false, BUG-018), so during the page's inline
        // render the Livewire component stack is [page, widget] and the
        // parent page is one below the top. Standalone renders get null.
        $stack = \Livewire\Mechanisms\HandleComponents\HandleComponents::$componentStack;
        $parent = $stack[count($stack) - 2] ?? null;
        $query = $parent instanceof \Filament\Resources\Pages\ListRecords
            ? $parent->getFilteredTableQuery()->clone()
            : null;

        return ['stats' => app(IncidentStatsFooterData::class)->build($query)];
    }
}
