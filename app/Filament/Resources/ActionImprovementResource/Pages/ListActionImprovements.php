<?php

declare(strict_types=1);

namespace App\Filament\Resources\ActionImprovementResource\Pages;

use App\Filament\Resources\ActionImprovementResource;
use App\Models\ActionImprovement;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListActionImprovements extends ListRecords
{
    protected static string $resource = ActionImprovementResource::class;

    public function mount(): void
    {
        // Owner 2026-10-14: incident groups newest-first by default; the
        // direction dropdown still lets users flip to ascending.
        $this->tableGroupingDirection = 'desc';

        parent::mount();
    }

    public function getTabs(): array
    {
        $base = fn (): Builder => ActionImprovementResource::getEloquentQuery();

        $pendingProofCount = $base()->withPendingProof()->count();
        $overdueCount = $base()
            ->whereIn('status', ActionImprovement::openStatuses())
            ->whereDate('due_date', '<', today())
            ->count();

        return [
            'all' => Tab::make('All'),
            'open' => Tab::make('Open')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', ActionImprovement::openStatuses())),
            'pending_proof' => Tab::make('Pending Proof Approval')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->withPendingProof())
                ->badge($pendingProofCount > 0 ? $pendingProofCount : null)
                ->badgeColor('warning'),
            'overdue' => Tab::make('Overdue')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', ActionImprovement::openStatuses())
                    ->whereDate('due_date', '<', today()))
                ->badge($overdueCount > 0 ? $overdueCount : null)
                ->badgeColor('danger'),
            'done' => Tab::make('Done')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ActionImprovement::STATUS_DONE)),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
