<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\ApiFormRequest;

class StoreIncidentRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['required', 'string'],
            'no' => ['required', 'string', 'max:255', 'unique:incidents,no'],
            'root_cause' => ['nullable', 'string'],
            'severity' => ['required', 'string'],
            'incident_type' => ['required', 'in:Tech,Non-tech'],
            'incident_source' => ['required', 'in:Internal,External'],
            'goc_upload' => ['boolean'],
            'teams_upload' => ['boolean'],
            'discovered_at' => ['nullable', 'date'],
            'stop_bleeding_at' => ['nullable', 'date'],
            'incident_date' => ['required', 'date'],
            'entry_date_tech_risk' => ['required', 'date'],
            'pic_id' => ['nullable', 'exists:users,id'],
            'reported_by' => ['nullable', 'string'],
            'third_party_client' => ['nullable', 'string'],
            'potential_fund_loss' => ['nullable', 'numeric'],
            'fund_loss' => ['nullable', 'numeric'],
            'checker' => ['nullable', 'string'],
            'maker' => ['nullable', 'string'],
        ];
    }
}
