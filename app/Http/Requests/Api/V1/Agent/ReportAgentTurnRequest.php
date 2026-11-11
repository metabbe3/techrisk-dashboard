<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Agent;

use App\Http\Requests\Api\ApiFormRequest;

class ReportAgentTurnRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'string', 'max:190'],
            'conversation_type' => ['nullable', 'string', 'in:1,2'],
            'staff_id' => ['nullable', 'string', 'max:64'],
            'nick' => ['nullable', 'string', 'max:128'],
            'user_text' => ['required', 'string', 'max:10000'],
            'assistant_text' => ['nullable', 'string', 'max:20000'],
            'model' => ['nullable', 'string', 'max:64'],
            'usage' => ['nullable', 'array'],
            'usage.prompt_tokens' => ['nullable', 'integer'],
            'usage.completion_tokens' => ['nullable', 'integer'],
            'usage.total_tokens' => ['nullable', 'integer'],
            'response_time_ms' => ['nullable', 'integer'],
            'error' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
