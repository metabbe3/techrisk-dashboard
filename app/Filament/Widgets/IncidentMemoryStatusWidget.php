<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Markdown\IncidentMarkdownCorpusService;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

/**
 * Persistent evidence of the incident memory on its home page (Agent
 * Memory): incident count, built-at, Fresh/Stale. The rebuild button's
 * notification fades — this panel stays (owner 2026-10-01).
 */
class IncidentMemoryStatusWidget extends Widget
{
    protected static string $view = 'filament.widgets.incident-memory-status-widget';

    protected static ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    /**
     * The rebuild button lives on the page, not here. Its global dispatch
     * hits this listener, and any action call forces a re-render — so the
     * panel updates the moment a rebuild finishes, no reload needed.
     */
    #[On('incident-memory-rebuilt')]
    public function refreshed(): void {}

    /**
     * @return array{built: bool, incidents: int, built_at: string|null, stale: bool}
     */
    public function memoryStatus(): array
    {
        $service = app(IncidentMarkdownCorpusService::class);
        $manifest = $service->manifest();

        if ($manifest === null) {
            return ['built' => false, 'incidents' => 0, 'built_at' => null, 'stale' => false];
        }

        return [
            'built' => true,
            'incidents' => (int) ($manifest['incidents'] ?? 0),
            'built_at' => $manifest['built_at'] ?? null,
            'stale' => $service->isStale(),
        ];
    }
}
