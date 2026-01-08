<?php

namespace App\Livewire;

use Livewire\Component;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\DatePicker;

class DashboardFilter extends Component implements HasForms
{
    use InteractsWithForms;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'start_date' => now()->startOfMonth(),
            'end_date' => now()->endOfMonth(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                DatePicker::make('start_date'),
                DatePicker::make('end_date'),
            ]);
    }

    public function updated(array $data): void
    {
        $this->dispatch('dashboardFiltersUpdated', $this->data);
    }

    public function render()
    {
        return view('livewire.dashboard-filter');
    }
}