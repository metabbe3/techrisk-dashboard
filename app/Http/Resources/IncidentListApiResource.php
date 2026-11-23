<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentListApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_incident_id' => $this->parent_incident_id, // PROJ-022: set on sub-incidents
            'parent_incident_no' => $this->whenLoaded('superIncident', fn () => $this->superIncident?->no),
            'sub_incidents_count' => $this->whenCounted('subIncidents'),
            'incident_no' => $this->no,
            'incident_title' => $this->title,
            'summary' => $this->summary,
            'severity' => $this->severity,
            // Plain Y-m-d — the agent's external-RCA tool parses exactly this
            // shape; ISO datetimes (detail resource) are for display only.
            'incident_date' => $this->incident_date?->format('Y-m-d'),
            // The agent's watch poller diffs status on the updated_since sweep —
            // without it a changed row can't say WHAT changed.
            'incident_status' => $this->incident_status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
