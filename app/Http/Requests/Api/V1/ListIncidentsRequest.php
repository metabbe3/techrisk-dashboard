<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\FundStatus;
use App\Enums\IncidentClassification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListIncidentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'min_fund_loss' => ['nullable', 'numeric', 'min:0'],
            'max_fund_loss' => ['nullable', 'numeric', 'min:0'],
            'min_potential_fund_loss' => ['nullable', 'numeric', 'min:0'],
            'max_potential_fund_loss' => ['nullable', 'numeric', 'min:0'],
            'tags' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:Tech,Non-tech'],
            'severity' => ['nullable', 'string', Rule::enum(Severity::class)],
            'incident_status' => ['nullable', 'string', Rule::enum(IncidentStatus::class)],
            'classification' => ['nullable', 'string', Rule::enum(IncidentClassification::class)],
            'fund_status' => ['nullable', 'string', Rule::enum(FundStatus::class)],
            'pic_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'min:2'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
