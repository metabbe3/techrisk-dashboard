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
            'parent_incident_id' => $this->parent_incident_id, // PROJ-022: set on sub-incidents
            'incident_no' => $this->no,
            'incident_title' => $this->title,
            'summary' => $this->summary,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
