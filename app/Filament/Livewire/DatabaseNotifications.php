<?php

namespace App\Filament\Livewire;

use Filament\Notifications\Livewire\DatabaseNotifications as BaseDatabaseNotifications;

class DatabaseNotifications extends BaseDatabaseNotifications
{
    /**
     * Polling interval for database notifications
     * Broadcasting is disabled, so polling is used instead of WebSocket
     */
    public function getPollingInterval(): ?string
    {
        return '30s'; // Poll every 30 seconds
    }
}
