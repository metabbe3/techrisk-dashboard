<?php

namespace App\Filament\Livewire;

use Filament\Notifications\Livewire\DatabaseNotifications as BaseDatabaseNotifications;

class DatabaseNotifications extends BaseDatabaseNotifications
{
    /**
     * Override to disable polling and prevent modal auto-close
     */
    public function getPollingInterval(): ?string
    {
        return null; // Disable polling completely
    }
}
