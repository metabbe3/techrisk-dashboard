<?php

namespace App\Filament\Livewire;

use Filament\Notifications\Livewire\DatabaseNotifications as BaseDatabaseNotifications;

class DatabaseNotifications extends BaseDatabaseNotifications
{
    /**
     * Set reasonable polling interval as fallback when broadcasting is not available
     * With Reverb broadcasting enabled, this serves as a backup update mechanism
     */
    public function getPollingInterval(): ?string
    {
        return '30s'; // Poll every 30 seconds as fallback
    }
}
