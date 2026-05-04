<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIncidentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['string', 'max:255'],
            'summary' => ['string'],
            'no' => ['string', 'max:255', 'unique:incidents,no,'.$this->route('incident')?->id],
            'root_cause' => ['nullable', 'string'],
            'severity' => ['string'],
            'incident_type' => ['in:Tech,Non-tech'],
            'incident_source' => ['in:Internal,External'],
            'goc_upload' => ['boolean'],
            'teams_upload' => ['boolean'],
            'discovered_at' => ['nullable', 'date'],
            'stop_bleeding_at' => ['nullable', 'date'],
            'incident_date' => ['date'],
            'entry_date_tech_risk' => ['date'],
            'pic_id' => ['nullable', 'exists:users,id'],
            'reported_by' => ['nullable', 'string'],
            'third_party_client' => ['nullable', 'string'],
            'potential_fund_loss' => ['nullable', 'numeric'],
            'fund_loss' => ['nullable', 'numeric'],
            'people_caused' => ['nullable', 'array'],
            'people_caused.*' => ['nullable', 'string', 'max:255'],
            'checker' => ['nullable', 'string'],
            'maker' => ['nullable', 'string'],
        ];
    }
}
