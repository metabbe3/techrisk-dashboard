<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IncidentResource extends JsonResource
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
            'title' => $this->title,
            'summary' => $this->summary,
            'root_cause' => $this->root_cause,
            'severity' => $this->severity,
            'mttr' => $this->mttr,
            'mtbf' => $this->mtbf,
            'goc_upload' => $this->goc_upload,
            'teams_upload' => $this->teams_upload,
            'discovered_at' => $this->discovered_at,
            'stop_bleeding_at' => $this->stop_bleeding_at,
            'incident_date' => $this->incident_date,
            'entry_date_tech_risk' => $this->entry_date_tech_risk,
            'reported_by' => $this->reported_by,
            'involved_third_party' => $this->involved_third_party,
            'potential_fund_loss' => $this->potential_fund_loss,
            'fund_loss' => $this->fund_loss,
            'people_caused' => $this->people_caused,
            'checker' => $this->checker,
            'maker' => $this->maker,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'incident_type' => new IncidentTypeResource($this->whenLoaded('incidentType')),
            'pic' => new UserResource($this->whenLoaded('pic')),
            'status_updates' => StatusUpdateResource::collection($this->whenLoaded('statusUpdates')),
            'investigation_documents' => InvestigationDocumentResource::collection($this->whenLoaded('investigationDocuments')),
            'labels' => LabelResource::collection($this->whenLoaded('labels')),
            'action_improvements' => ActionImprovementResource::collection($this->whenLoaded('actionImprovements')),
        ];
    }
}
