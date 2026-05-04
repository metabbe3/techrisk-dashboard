<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateActionImprovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['string', 'max:255'],
            'detail' => ['string'],
            'due_date' => ['date'],
            'pic_email' => ['array'],
            'reminder' => ['boolean'],
            'reminder_frequency' => ['nullable', 'string'],
            'status' => ['string', 'in:pending,done'],
        ];
    }
}
