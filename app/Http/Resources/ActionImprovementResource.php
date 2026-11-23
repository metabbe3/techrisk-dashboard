<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActionImprovementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'incident_no' => $this->when($this->relationLoaded('incident'), fn () => $this->incident->no),
            'title' => $this->title,
            'detail' => $this->detail,
            'due_date' => $this->due_date,
            'pic_email' => $this->when($request->user()?->currentAccessToken()?->can('read:pii'), $this->pic_email),
            'reminder' => $this->reminder,
            'reminder_frequency' => $this->reminder_frequency,
            'status' => $this->status,
            'has_pending_proof' => isset($this->pending_proof_count)
                ? (int) $this->pending_proof_count > 0
                : $this->hasPendingProof(),
            'pending_proof_count' => isset($this->pending_proof_count)
                ? (int) $this->pending_proof_count
                : ($this->relationLoaded('evidence')
                    ? $this->evidence->filter(fn ($e) => filled($e->submitted_by) && $e->approved_at === null)->count()
                    : 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
