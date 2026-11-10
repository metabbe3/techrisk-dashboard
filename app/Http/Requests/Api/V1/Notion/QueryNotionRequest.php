<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notion;

use App\Http\Requests\Api\ApiFormRequest;

class QueryNotionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'database' => ['required', 'string', 'min:4', 'max:2048'],
            'query' => ['required', 'string', 'min:4', 'max:4000'],
        ];
    }
}
