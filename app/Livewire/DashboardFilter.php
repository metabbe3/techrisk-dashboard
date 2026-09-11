<?php

namespace App\Livewire;

use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Livewire\Component;

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

    public function updated(string $name, mixed $value): void
    {
        // Livewire 3 calls updated(string $name, mixed $value) — the old
        // (array $data) signature threw a TypeError on every picker change.
        if (str_starts_with($name, 'data.') && isset($this->data['end_date'])) {
            // DatePicker values are midnight-only; consumers compare with
            // whereBetween, so normalize to keep the last day in range.
            $this->data['end_date'] = Carbon::parse($this->data['end_date'])
                ->endOfDay()
                ->format('Y-m-d H:i:s');
        }

        $this->dispatch('dashboardFiltersUpdated', $this->data);
    }

    public function render()
    {
        return view('livewire.dashboard-filter');
    }
}
