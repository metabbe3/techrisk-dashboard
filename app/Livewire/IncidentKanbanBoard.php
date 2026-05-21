<?php

namespace App\Livewire;

use App\Enums\FundStatus;
use App\Enums\IncidentStatus;
use App\Enums\IncidentType;
use App\Enums\Severity;
use App\Filament\Resources\IncidentResource;
use App\Models\Incident;
use App\Models\StatusUpdate;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class IncidentKanbanBoard extends Component
{
    public array $severity = [];

    public array $incidentType = [];

    public ?string $fundStatus = null;

    public array $picId = [];

    public string $searchQuery = '';

    public string $quickPeriod = 'year';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public bool $filtersVisible = false;

    public function mount(): void
    {
        $this->quickPeriod = 'year';
    }

    #[Computed]
    public function columns(): array
    {
        return collect(IncidentStatus::cases())->map(fn (IncidentStatus $status) => [
            'value' => $status->value,
            'label' => $status->value,
            'color' => $status->color(),
        ])->toArray();
    }

    #[Computed]
    public function incidents(): Collection
    {
        $query = $this->buildQuery();

        $incidents = $query->get();

        return collect(IncidentStatus::cases())->mapWithKeys(function (IncidentStatus $status) use ($incidents) {
            return [$status->value => $incidents->where('incident_status', $status->value)->values()];
        });
    }

    #[Computed]
    public function totalCounts(): array
    {
        $baseQuery = $this->buildFilteredBaseQuery();

        return collect(IncidentStatus::cases())->mapWithKeys(function (IncidentStatus $status) use ($baseQuery) {
            $count = (clone $baseQuery)->where('incident_status', $status->value)->count();

            return [$status->value => $count];
        })->toArray();
    }

    #[Computed]
    public function severityOptions(): array
    {
        return Severity::options();
    }

    #[Computed]
    public function incidentTypeOptions(): array
    {
        return IncidentType::options();
    }

    #[Computed]
    public function fundStatusOptions(): array
    {
        return FundStatus::filterOptions();
    }

    #[Computed]
    public function picOptions(): array
    {
        return User::orderBy('name')->pluck('name', 'id')->toArray();
    }

    public function updateStatus(int $incidentId, string $newStatus): void
    {
        $validStatuses = collect(IncidentStatus::cases())->map->value->toArray();

        if (! in_array($newStatus, $validStatuses)) {
            $this->js("console.error('Invalid status: {$newStatus}')");

            return;
        }

        if (! auth()->user()->can('manage incidents')) {
            Notification::make()
                ->danger()
                ->title('Unauthorized')
                ->body('You do not have permission to update incident status.')
                ->send();

            return;
        }

        $incident = Incident::find($incidentId);

        if (! $incident) {
            return;
        }

        $oldStatus = $incident->incident_status;
        $incident->incident_status = $newStatus;
        $incident->save();

        StatusUpdate::create([
            'incident_id' => $incident->id,
            'status' => $newStatus,
            'notes' => "Changed from {$oldStatus} to {$newStatus} via Board View",
            'update_date' => now(),
        ]);

        unset($this->incidents, $this->totalCounts);

        Notification::make()
            ->success()
            ->title('Status Updated')
            ->body("**{$incident->no}** moved from {$oldStatus} to {$newStatus}")
            ->send();
    }

    public function toggleFilters(): void
    {
        $this->filtersVisible = ! $this->filtersVisible;
    }

    public function toggleFilter(string $property, string $value): void
    {
        if (! property_exists($this, $property)) {
            return;
        }

        $array = $this->$property;

        if (in_array($value, $array)) {
            $this->$property = array_values(array_diff($array, [$value]));
        } else {
            $this->$property = array_values(array_merge($array, [$value]));
        }

        unset($this->incidents, $this->totalCounts);
    }

    public function resetFilters(): void
    {
        $this->reset(['severity', 'incidentType', 'fundStatus', 'picId', 'searchQuery', 'dateFrom', 'dateTo']);
        $this->quickPeriod = 'year';
        unset($this->incidents, $this->totalCounts);
    }

    public function render()
    {
        return view('livewire.incident-kanban-board');
    }

    private function buildQuery(): Builder
    {
        $query = $this->buildFilteredBaseQuery();

        $query->select([
            'id', 'no', 'title', 'severity', 'incident_status',
            'pic_id', 'incident_date', 'fund_status',
            'potential_fund_loss', 'recovered_fund', 'fund_loss',
        ])
            ->with('pic')
            ->orderBy('incident_date', 'desc')
            ->limit(200);

        return $query;
    }

    private function buildFilteredBaseQuery(): Builder
    {
        $query = Incident::query();

        $query = IncidentResource::applyAccessControl($query);

        if (! empty($this->severity)) {
            $query->whereIn('severity', $this->severity);
        }

        if (! empty($this->incidentType)) {
            $query->whereIn('incident_type', $this->incidentType);
        }

        if ($this->fundStatus) {
            $query->where('fund_status', $this->fundStatus);
        }

        if (! empty($this->picId)) {
            $query->whereIn('pic_id', $this->picId);
        }

        if (strlen($this->searchQuery) >= 2) {
            $query->where(function (Builder $q) {
                $q->where('no', 'like', "%{$this->searchQuery}%")
                    ->orWhere('title', 'like', "%{$this->searchQuery}%");
            });
        }

        $this->applyDateFilter($query);

        return $query;
    }

    private function applyDateFilter(Builder $query): void
    {
        if ($this->dateFrom) {
            $query->whereDate('incident_date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('incident_date', '<=', $this->dateTo);
        }

        if (! $this->dateFrom && ! $this->dateTo) {
            match ($this->quickPeriod) {
                'year' => $query->whereYear('incident_date', now()->year),
                'quarter' => $query->whereBetween('incident_date', [now()->startOfQuarter(), now()->endOfQuarter()]),
                'month' => $query->whereMonth('incident_date', now()->month)->whereYear('incident_date', now()->year),
                'all' => null,
                default => $query->whereYear('incident_date', now()->year),
            };
        }
    }
}
