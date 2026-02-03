<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiAuditLogResource\Pages;

use App\Filament\Resources\ApiAuditLogResource;
use App\Models\UserAuditLogSetting;
use Filament\Resources\Pages\ListRecords;

class ListApiAuditLogs extends ListRecords
{
    protected static string $resource = ApiAuditLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No actions - audit logs are read-only
        ];
    }

    public function getTitle(): string
    {
        return 'API Audit Logs';
    }

    public function getSubheading(): ?string
    {
        $user = auth()->user();
        $settings = UserAuditLogSetting::forUser($user);

        if ($user->hasRole('admin')) {
            return 'Admin Access - Viewing all logs';
        }

        $yearsText = $settings->getAllowedYearsStringAttribute();

        return "Incident logs only - Accessible years: {$yearsText}";
    }
}
