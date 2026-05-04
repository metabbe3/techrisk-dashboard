<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreActionImprovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'detail' => ['required', 'string'],
            'due_date' => ['required', 'date'],
            'pic_email' => ['required', 'array'],
            'reminder' => ['boolean'],
            'reminder_frequency' => ['nullable', 'string'],
            'status' => ['string', 'in:pending,done'],
        ];
    }
}
