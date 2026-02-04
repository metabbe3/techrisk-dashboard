<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentApiResource extends JsonResource
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
            'incident_no' => $this->no,
            'incident_name' => $this->title,
            'severity' => $this->severity,
            'date' => $this->incident_date,
            'summary' => $this->summary,
            'url' => route('filament.admin.resources.incidents.view', ['record' => $this->id]),
        ];
    }
}
