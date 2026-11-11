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
            'incident_no' => $this->no,
            'incident_title' => $this->title,
            'summary' => $this->summary,
            // The agent's watch poller diffs status on the updated_since sweep —
            // without it a changed row can't say WHAT changed.
            'incident_status' => $this->incident_status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
