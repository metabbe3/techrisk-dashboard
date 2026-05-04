<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Livewire\Attributes\On;

trait InteractsWithDashboardFilters
{
    #[On('dashboardFiltersUpdated')]
    public function updateDashboardFilters(array $data): void
    {
        $this->start_date = $data['start_date'];
        $this->end_date = $data['end_date'];
    }
}
