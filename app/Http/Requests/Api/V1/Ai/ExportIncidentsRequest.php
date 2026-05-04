<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Ai;

use Illuminate\Foundation\Http\FormRequest;

class ExportIncidentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'severity' => ['nullable', 'string', 'in:P1,P2,P3,P4,G,X1,X2,X3,X4,Non Incident'],
            'type' => ['nullable', 'string', 'in:Tech,Non-tech,Company Loss'],
        ];
    }
}
