<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentListApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'incident_no' => $this->no,
            'incident_title' => $this->title,
            'summary' => $this->summary,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
