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
            'summary' => $this->summary,
            'root_cause' => $this->root_cause,
            'remark' => $this->remark,
            'improvements' => $this->improvements,
            'timeline' => $this->timeline,
            'severity' => $this->severity,
            'incident_type' => $this->incident_type,
            'incident_source' => $this->incident_source,
            'incident_category' => $this->incident_category,
            'incident_status' => $this->incident_status,
            'classification' => $this->classification,
            'glitch_flag' => $this->glitch_flag,
            'mttr' => $this->mttr,
            'mtbf' => $this->mtbf,
            'fund_status' => $this->fund_status,
            'potential_fund_loss' => $this->potential_fund_loss,
            'recovered_fund' => $this->recovered_fund,
            'fund_loss' => $this->fund_loss,
            'loss_taken_by' => $this->loss_taken_by,
            'incident_date' => $this->incident_date,
            'entry_date_tech_risk' => $this->entry_date_tech_risk,
            'discovered_at' => $this->discovered_at,
            'stop_bleeding_at' => $this->stop_bleeding_at,
            'pic_id' => $this->pic_id,
            'reported_by' => $this->reported_by,
            'third_party_client' => $this->third_party_client,
            'evidence' => $this->evidence,
            'evidence_link' => $this->evidence_link,
            'risk_incident_form_cfm' => $this->risk_incident_form_cfm,
            'action_improvement_tracking' => $this->action_improvement_tracking,
            'goc_upload' => $this->goc_upload,
            'teams_upload' => $this->teams_upload,
            'doc_signed' => $this->doc_signed,
            'investigation_pic_status' => $this->investigation_pic_status,
            'labels' => $this->whenLoaded('labels', $this->labels),
            'pic' => $this->whenLoaded('pic', $this->pic),
            'status_updates' => $this->whenLoaded('statusUpdates', $this->statusUpdates),
            'action_improvements' => $this->whenLoaded('actionImprovements', $this->actionImprovements),
            'investigation_documents' => $this->whenLoaded('investigationDocuments', $this->investigationDocuments),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
