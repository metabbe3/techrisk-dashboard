<?php

declare(strict_types=1);
namespace App\Filament\Resources\IncidentResource\Pages;

use App\Filament\Resources\IncidentResource;
use App\Models\DohaTicket;
use Filament\Forms\Form;
use Filament\Resources\Pages\CreateRecord;

class CreateIncident extends CreateRecord
{
    protected static string $resource = IncidentResource::class;

    /**
     * DOHA integration (owner 2026-11-23): the DOHA Tickets "Create Incident
     * from this ticket" action lands here with ?doha=<doha_id> — the form is
     * pre-filled from the ticket and the ticket is linked after the create.
     * Only an UNLINKED ticket prefills/links, and only for 'manage incidents'
     * users (the same gate as the buttons).
     */
    public ?int $dohaTicketId = null;

    public function form(Form $form): Form
    {
        return $form
            ->schema(IncidentResource::getFormSchema('create'))
            ->columns(12);
    }

    protected function fillForm(): void
    {
        parent::fillForm();

        $ticket = $this->resolveDohaTicket();

        if ($ticket !== null) {
            $this->dohaTicketId = $ticket->getKey();

            // Merge ONTO the current state: a bare fill() would wipe the
            // other fields' defaults (no = generateNo, incident_status...).
            $this->form->fill(
                array_merge($this->form->getRawState(), $ticket->incidentPrefillAttributes())
            );
        }
    }

    private function resolveDohaTicket(): ?DohaTicket
    {
        $dohaId = request()->query('doha');

        if (! filled($dohaId)) {
            return null;
        }

        $ticket = DohaTicket::query()->where('doha_id', $dohaId)->first();

        if ($ticket === null || $ticket->incident_id !== null) {
            return null;
        }

        // Same gate as the buttons on the ticket page.
        if (! auth()->user()?->can('manage incidents')) {
            return null;
        }

        return $ticket;
    }

    protected function afterCreate(): void
    {
        if ($this->dohaTicketId !== null) {
            // Unlinked-guard: never overwrite a link another admin already set.
            DohaTicket::query()
                ->whereKey($this->dohaTicketId)
                ->whereNull('incident_id')
                ->update(['incident_id' => $this->record->id]);
        }
    }

    protected function useDatabaseTransactions(): bool
    {
        return true;
    }
}
