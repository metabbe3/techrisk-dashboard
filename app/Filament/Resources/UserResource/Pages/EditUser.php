<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\UserAuditLogSetting;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->databaseTransaction(),
        ];
    }

    protected function useDatabaseTransactions(): bool
    {
        return true;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        // Extract audit log settings before parent update
        $auditLogSettings = $data['audit_log_settings'] ?? null;
        unset($data['audit_log_settings']);

        // Update user
        $record = parent::handleRecordUpdate($record, $data);

        // Update audit log settings if provided
        if ($auditLogSettings !== null) {
            UserAuditLogSetting::updateOrCreate(
                ['user_id' => $record->id],
                [
                    'allowed_years' => $auditLogSettings['allowed_years'] ?? [],
                    'can_view_all_logs' => $auditLogSettings['can_view_all_logs'] ?? false,
                ]
            );
        }

        return $record;
    }
}

