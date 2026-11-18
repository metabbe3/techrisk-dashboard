<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use App\Http\Requests\Api\ApiFormRequest;

class CreateAgentScheduleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'string', 'max:190'],
            'conversation_type' => ['required', 'string', 'in:1,2'],
            'staff_id' => ['required', 'string', 'max:64'],
            'nick' => ['nullable', 'string', 'max:128'],
            'mode' => ['required', 'string', 'in:message,task'],
            'text' => ['required', 'string', 'max:1000'],
            'cron' => ['nullable', 'string', 'max:64', 'required_without:run_at'],
            'run_at' => ['nullable', 'date', 'required_without:cron'],
        ];
    }
}
