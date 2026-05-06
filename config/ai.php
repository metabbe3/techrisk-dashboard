<?php

return [

    'base_url' => env('AI_API_BASE_URL'),

    'api_key' => env('AI_API_KEY'),

    'default_model' => env('AI_API_DEFAULT_MODEL', 'SMART-MODEL'),

    'timeout' => env('AI_API_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | AI Models
    |--------------------------------------------------------------------------
    |
    | Available models for text enhancement. Populated via admin settings page.
    | Key-value pairs: model ID => display name.
    |
    */
    'models' => [
        'FAST-MODEL' => 'Fast Model',
        'SMART-MODEL' => 'Smart Model',
        'REASONING-MODEL' => 'Reasoning Model',
    ],

    /*
    |--------------------------------------------------------------------------
    | Field-Specific Prompts
    |--------------------------------------------------------------------------
    |
    | Each text field gets its own system prompt and UI label.
    | Customizable per deployment without code changes.
    |
    */
    'prompts' => [
        'summary' => [
            'system' => "You are a technical incident analyst. Improve the given incident summary.\n\nRules:\n- Use plain text only. No markdown, no asterisks, no dashes, no special symbols.\n- Structure with clear labels like: Issue, Impact, Root Cause, Actions Taken.\n- Be concise and factual. Preserve all original details.\n- Use line breaks and spacing for readability.\n- Output only the improved text, no preamble.",
            'label' => 'Enhance Summary',
        ],
        'root_cause' => [
            'system' => "You are a root cause analysis expert. Improve the given root cause analysis.\n\nRules:\n- Use plain text only. No markdown, no asterisks, no dashes, no special symbols.\n- Structure with clear sections: Primary Cause, Contributing Factors, Evidence.\n- Be thorough but concise. Preserve all original details.\n- Use numbered lists with plain numbering (1. 2. 3.).\n- Output only the improved text, no preamble.",
            'label' => 'Enhance Root Cause',
        ],
        'timeline' => [
            'system' => "You are an incident timeline analyst. Improve the given incident timeline.\n\nRules:\n- Use plain text only. No markdown, no asterisks, no dashes, no bullet points.\n- Format each event on its own line with a clear time reference.\n- Use format: [Time] Event description\n- Ensure chronological order. Add estimated timestamps if implied.\n- Output only the improved timeline, no preamble.",
            'label' => 'Enhance Timeline',
        ],
        'remark' => [
            'system' => "You are a professional technical writer. Improve the given remark.\n\nRules:\n- Use plain text only. No markdown, no asterisks, no special formatting.\n- Improve clarity, grammar, and professional tone.\n- Keep it concise. Preserve the original meaning.\n- Use simple sentences and paragraphs.\n- Output only the improved text, no preamble.",
            'label' => 'Enhance Remark',
        ],
    ],

    'rate_limit_per_minute' => env('AI_RATE_LIMIT_PER_MINUTE', 10),

    'max_input_length' => env('AI_MAX_INPUT_LENGTH', 5000),

];
