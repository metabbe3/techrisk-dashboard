<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Filament\Resources\IncidentResource;
use App\Models\DohaTicket;
use App\Models\Incident;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;

/**
 * The bridge between a DOHA ticket and a portal Incident (owner 2026-11-23):
 * an admin may create an Incident pre-filled from the ticket, or link the
 * ticket to an existing one — nothing automatic, the mirror itself never
 * writes Incidents. View-page-header ONLY (Filament page actions are a
 * different class than table row actions — the row variant 500'd on
 * Action::table; keep them off the list rows). The visible() closures ARE
 * the server-side gate (enforced on mountAction/callMountedAction — pinned
 * by DohaBridgeActionsAuthorizationTest); they carry 'manage incidents'.
 */
class DohaTicketIncidentActions
{
    public static function canManage(): bool
    {
        return (bool) auth()->user()?->can('manage incidents');
    }

    public static function createIncidentAction(): Action
    {
        return Action::make('createIncident')
            ->label('Create Incident from this ticket')
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->visible(fn (DohaTicket $record): bool => $record->incident_id === null && self::canManage())
            ->url(fn (DohaTicket $record): string => IncidentResource::getUrl('create', ['doha' => $record->doha_id]));
    }

    public static function linkAction(): Action
    {
        return Action::make('linkIncident')
            ->label('Link to existing incident')
            ->icon('heroicon-o-link')
            ->visible(fn (DohaTicket $record): bool => $record->incident_id === null && self::canManage())
            ->form([
                Select::make('incident_id')
                    ->label('Incident')
                    ->searchable()
                    ->required()
                    ->getSearchResultsUsing(fn (string $search): array => Incident::query()
                        ->where('no', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orderByDesc('incident_date')
                        ->limit(50)
                        ->get()
                        ->mapWithKeys(fn (Incident $incident): array => [
                            $incident->id => "{$incident->no} — {$incident->title}",
                        ])
                        ->all()),
            ])
            ->action(function (array $data, DohaTicket $record): void {
                $record->update(['incident_id' => $data['incident_id']]);

                Notification::make()->success()
                    ->title('Ticket linked to '.$record->incident->no)
                    ->send();
            });
    }

    public static function unlinkAction(): Action
    {
        return Action::make('unlinkIncident')
            ->label('Unlink incident')
            ->icon('heroicon-o-link-slash')
            ->requiresConfirmation()
            ->modalDescription('Unlink this DOHA ticket from its incident? The ticket itself stays.')
            ->visible(fn (DohaTicket $record): bool => $record->incident_id !== null && self::canManage())
            ->action(function (DohaTicket $record): void {
                $record->update(['incident_id' => null]);

                Notification::make()->success()->title('Ticket unlinked')->send();
            });
    }
}
