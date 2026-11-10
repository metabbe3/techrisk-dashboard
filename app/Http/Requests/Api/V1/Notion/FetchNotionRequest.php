<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Notion;

use App\Http\Requests\Api\ApiFormRequest;

class FetchNotionRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'min:8', 'max:2048'],
        ];
    }
}
