<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use App\Http\Requests\Api\ApiFormRequest;

class ListAgentSchedulesRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'string', 'max:190'],
        ];
    }
}
