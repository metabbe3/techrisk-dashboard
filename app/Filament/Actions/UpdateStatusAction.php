<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use Filament\Actions\Action as HeaderAction;
use Filament\Actions\MountableAction;
use Filament\Forms;
use Filament\Tables\Actions\Action as TableAction;

/**
 * ONE definition of "Update Status" (owner 2026-11-21: the incident view
 * header's kebab must be able to change the status WITHOUT opening Edit).
 * Used by the incidents list kebab (table action) and the view page header —
 * the form, the BUG-035 closure-gate field rule and the write live here so
 * the two surfaces cannot drift.
 */
final class UpdateStatusAction
{
    /**
     * @template T of MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function configure(MountableAction $action): MountableAction
    {
        return $action
            ->label('Update Status')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->form([
                Forms\Components\Select::make('incident_status')
                    ->label('Status')
                    ->options(IncidentStatus::options())
                    ->required()
                    // BUG-035: this modal has no RCA/stop-bleed fields — check the record.
                    ->rule(fn (Incident $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        // State arrives as the enum here (record-bound modal), a string on the page.
                        $status = $value instanceof IncidentStatus ? $value : IncidentStatus::tryFrom((string) $value);
                        if ($status !== IncidentStatus::Completed || $record->incident_status === IncidentStatus::Completed) {
                            return;
                        }
                        $error = Incident::closureGateError($record->root_cause, $record->root_cause_category, $record->stop_bleeding_at);
                        if ($error !== null) {
                            $fail($error.' Use Edit to add it first.');
                        }
                    })
                    ->default(fn (Incident $record) => $record->incident_status),
                Forms\Components\Textarea::make('remark')
                    ->label('Notes')
                    ->required()
                    ->rows(3)
                    ->default(fn (Incident $record) => $record->remark),
            ])
            ->action(function (Incident $record, array $data) {
                // update_date belongs to the status_updates timeline, NOT
                // incident_date — writing it here corrupted the occurrence date
                // (and every MTTR/MTBF/year-scoped number derived from it) on
                // every routine update.
                $record->update([
                    'incident_status' => $data['incident_status'],
                    'remark' => $data['remark'],
                ]);
            })
            ->visible(fn (): bool => (bool) auth()->user()?->can('manage incidents'));
    }

    /** Header action (view page kebab). */
    public static function header(): HeaderAction
    {
        /** @var HeaderAction $action */
        $action = self::configure(HeaderAction::make('update_status'));

        return $action;
    }

    /** Table row action (incidents list kebab). */
    public static function table(): TableAction
    {
        /** @var TableAction $action */
        $action = self::configure(TableAction::make('update_status'));

        return $action;
    }
}
