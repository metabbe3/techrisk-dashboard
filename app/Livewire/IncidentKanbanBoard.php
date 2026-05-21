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
    private const SLA_THRESHOLDS = [
        'P1' => 24,
        'P2' => 48,
        'P3' => 72,
        'P4' => 168,
    ];

    public array $severity = [];

    public array $incidentType = [];

    public ?string $fundStatus = null;

    public array $picId = [];

    public string $searchQuery = '';

    public string $quickPeriod = 'year';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public bool $filtersVisible = false;

    public bool $assignedToMe = false;

    public bool $p1Only = false;

    public bool $unassignedOnly = false;

    public bool $fundLossOnly = false;

    public bool $showAllCompleted = false;

    public ?int $selectedIncidentId = null;

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
            $items = $incidents->where('incident_status', $status->value)->values();

            if ($status->value === 'Completed' && ! $this->showAllCompleted && $items->count() > 10) {
                $items = $items->slice(0, 10)->values();
            }

            return [$status->value => $items];
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
        $this->reset(['severity', 'incidentType', 'fundStatus', 'picId', 'searchQuery', 'dateFrom', 'dateTo', 'assignedToMe', 'p1Only', 'unassignedOnly', 'fundLossOnly']);
        $this->quickPeriod = 'year';
        unset($this->incidents, $this->totalCounts);
    }

    public function toggleQuickFilter(string $property): void
    {
        if (! property_exists($this, $property)) {
            return;
        }

        $this->$property = ! $this->$property;
        unset($this->incidents, $this->totalCounts);
    }

    public function toggleShowAllCompleted(): void
    {
        $this->showAllCompleted = ! $this->showAllCompleted;
        unset($this->incidents);
    }

    public function viewIncident(int $incidentId): void
    {
        $this->selectedIncidentId = $incidentId;
    }

    public function closeIncidentPanel(): void
    {
        $this->selectedIncidentId = null;
    }

    #[Computed]
    public function selectedIncident(): ?Incident
    {
        if (! $this->selectedIncidentId) {
            return null;
        }

        return $this->incidents->flatten()->firstWhere('id', $this->selectedIncidentId);
    }

    public function getTimeInStatus(Incident $incident): array
    {
        $update = $incident->latestStatusUpdate;

        if (! $update || ! $update->update_date) {
            return ['text' => '', 'overdue' => false];
        }

        $diffInHours = (int) now()->diffInHours($update->update_date, true);

        if ($diffInHours < 1) {
            $text = round(now()->diffInMinutes($update->update_date, true)).'m';
        } elseif ($diffInHours < 24) {
            $text = $diffInHours.'h';
        } else {
            $text = intdiv($diffInHours, 24).'d';
        }

        $threshold = self::SLA_THRESHOLDS[$incident->severity] ?? 72;

        return ['text' => $text, 'overdue' => $diffInHours > $threshold];
    }

    public static function avatarColor(string $name): string
    {
        $colors = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ef4444', '#14b8a6'];

        return $colors[crc32($name) % count($colors)];
    }

    public static function initials(string $name): string
    {
        $parts = explode(' ', trim($name));

        if (count($parts) >= 2) {
            return strtoupper(substr($parts[0], 0, 1).substr($parts[1], 0, 1));
        }

        return strtoupper(substr($name, 0, 2));
    }

    public static function cleanTitle(string $title, $incidentDate): string
    {
        if (! $incidentDate) {
            return $title;
        }

        $patterns = [
            $incidentDate->format('Y-m-d'),
            $incidentDate->format('d/m/Y'),
            $incidentDate->format('m/d/Y'),
            $incidentDate->format('M d, Y'),
            $incidentDate->format('d M Y'),
            $incidentDate->format('Y M d'),
        ];

        $title = str_ireplace($patterns, '', $title);

        return trim(preg_replace('/\s+/', ' ', $title));
    }

    public static function formatCompactFinancial($potentialLoss, $actualLoss, $recovered, ?float $recoveryPct): ?string
    {
        if ($potentialLoss <= 0 && $actualLoss <= 0 && $recovered <= 0) {
            return null;
        }

        $parts = [];

        if ($actualLoss > 0) {
            $parts[] = 'Loss: '.self::shortMoney((float) $actualLoss);
        } elseif ($potentialLoss > 0) {
            $parts[] = 'Pot: '.self::shortMoney((float) $potentialLoss);
        }

        if ($recoveryPct !== null) {
            $parts[] = 'Rec: '.round($recoveryPct).'%';
        }

        return implode(' | ', $parts) ?: null;
    }

    private static function shortMoney(float $amount): string
    {
        if ($amount >= 1_000_000_000) {
            return 'Rp '.round($amount / 1_000_000_000, 1).'B';
        }

        if ($amount >= 1_000_000) {
            return 'Rp '.round($amount / 1_000_000, 1).'M';
        }

        if ($amount >= 1_000) {
            return 'Rp '.round($amount / 1_000, 1).'K';
        }

        return 'Rp '.number_format($amount, 0, ',', '.');
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
            'summary', 'incident_type', 'incident_category',
            'business_category', 'root_cause_category', 'responsible_team',
            'classification',
        ])
            ->with(['pic', 'latestStatusUpdate', 'labels', 'incidentType'])
            ->orderByRaw(Severity::fieldOrderExpression('severity'))
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

        if ($this->assignedToMe) {
            $query->where('pic_id', auth()->id());
        }

        if ($this->p1Only) {
            $query->where('severity', 'P1');
        }

        if ($this->unassignedOnly) {
            $query->whereNull('pic_id');
        }

        if ($this->fundLossOnly) {
            $query->where(function (Builder $q) {
                $q->where('potential_fund_loss', '>', 0)
                    ->orWhere('fund_loss', '>', 0);
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
