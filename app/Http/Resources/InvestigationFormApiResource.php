<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\InvestigationForm\InvestigationFormSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvestigationFormApiResource extends JsonResource
{
    /**
     * Compact, PII-safe read projection of an InvestigationFormRequest for the
     * DingTalk agent and external read-only API clients. Never exposes tokens
     * or raw answers.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canReadPii = (bool) $request->user()?->currentAccessToken()?->can('read:pii');
        $rawActions = $this->answers[InvestigationFormSchema::SECTION_ACTION_ITEMS] ?? [];
        $actionCount = is_array($rawActions)
            ? count(array_filter($rawActions, fn ($row): bool => is_array($row) && trim((string) ($row['item'] ?? '')) !== ''))
            : 0;

        return [
            'id' => $this->id,
            'incident_no' => $this->whenLoaded('incident', fn () => $this->incident?->no),
            'incident_title' => $this->whenLoaded('incident', fn () => $this->incident?->title),
            'incident_severity' => $this->whenLoaded('incident', fn () => $this->incident?->severity?->value ?? $this->incident?->severity),
            'team' => $this->team,
            'state' => $this->state(),
            'status' => $this->status,
            'expires_at' => $this->expires_at,
            'submitted_at' => $this->submitted_at,
            'has_draft' => filled($this->draft_updated_at),
            'draft_updated_at' => $this->draft_updated_at,
            'recipient_count' => (int) ($this->recipients_count ?? 0),
            'opened_count' => (int) ($this->opened_recipients_count ?? 0),
            'reminder_count' => (int) ($this->recipients_sum_reminder_count ?? 0),
            'action_items_count' => $actionCount,
            'submitter_name' => $this->when($canReadPii, $this->submitter_name),
            'submitter_email' => $this->when($canReadPii, $this->submitter_email),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
