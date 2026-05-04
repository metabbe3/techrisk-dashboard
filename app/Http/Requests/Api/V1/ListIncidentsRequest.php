<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
