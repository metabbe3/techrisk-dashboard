<?php

namespace App\Http\Resources\Ai;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * AI-optimized incident resource for bulk export.
 * Includes essential fields for AI ingestion while keeping payload size minimal.
 */
class IncidentExportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'no' => $this->no,
            'title' => $this->title,
            'summary' => $this->summary,
            'root_cause' => $this->root_cause,
            'timeline' => $this->timeline,
            'severity' => $this->severity,
            'incident_type' => $this->incident_type,
            'incident_source' => $this->incident_source,
            'incident_status' => $this->incident_status,
            'incident_date' => $this->incident_date?->format('Y-m-d\TH:i:s'),
            'discovered_at' => $this->discovered_at?->format('Y-m-d\TH:i:s'),
            'stop_bleeding_at' => $this->stop_bleeding_at?->format('Y-m-d\TH:i:s'),
            'entry_date_tech_risk' => $this->entry_date_tech_risk?->format('Y-m-d'),
            'fund_status' => $this->fund_status,
            'potential_fund_loss' => $this->potential_fund_loss,
            'recovered_fund' => $this->recovered_fund,
            'fund_loss' => $this->fund_loss,
            'reported_by' => $this->reported_by,
            'mttr' => $this->mttr,
            'mtbf' => $this->mtbf,
            'pic' => $this->pic?->only('name', 'email'),
            'labels' => $this->labels->pluck('name'),
            'created_at' => $this->created_at?->format('Y-m-d\TH:i:s'),
        ];
    }
}
