<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notion;

use App\Http\Requests\Api\ApiFormRequest;

class SearchNotionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:2', 'max:200'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
