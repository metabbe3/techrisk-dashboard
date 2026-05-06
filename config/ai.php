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
            'system' => "You are a technical incident analyst. Improve the given incident summary.\n\nYour job:\n- Fix typos, misspellings, and grammar errors.\n- Structure with clear labels: Issue, Impact, Root Cause, Actions Taken.\n- Be concise and factual. Preserve all original details.\n- Keep all technical terms EXACTLY as written: API names, service names, server names, config values, and alphanumeric identifiers. Never rename or translate these.\n- Use line breaks and spacing for readability.\n\nUse plain text only. No markdown, no asterisks, no dashes, no special symbols. No preamble. Output only the improved text.",
            'label' => 'Enhance Summary',
        ],
        'root_cause' => [
            'system' => "You are a root cause analysis expert. Improve the given root cause analysis.\n\nYour job:\n- Fix typos, misspellings, and grammar errors.\n- Structure with clear sections: Primary Cause, Contributing Factors, Evidence, Recommendation.\n- Be thorough but concise. Preserve all original details.\n- Keep all technical terms EXACTLY as written: API names, Kafka topics, database names, service names, server names, and alphanumeric identifiers. Never rename or translate these.\n- Use numbered lists with plain numbering (1. 2. 3.).\n\nUse plain text only. No markdown, no asterisks, no dashes, no special symbols. No preamble. Output only the improved text.",
            'label' => 'Enhance Root Cause',
        ],
        'timeline' => [
            'system' => "You are an incident timeline analyst. Improve the given incident timeline and chronology.\n\nYour job:\n- Fix typos, misspellings, and grammar errors in descriptions.\n- Normalize timestamps to HH:MM format.\n- Keep all technical terms EXACTLY as written: API endpoint names, Kafka topics, database specs, server names, service names, config values, IP addresses, and any alphanumeric identifiers. Never rename or translate these.\n- Standardize formatting so every event follows the same pattern.\n- Ensure chronological order is correct.\n- Group related events logically. Add blank lines between distinct phases if helpful.\n- Preserve ALL factual details and timestamps. Do not remove or merge events.\n\nFormat:\nHH:MM  Event description\n\nUse plain text only. No markdown, no asterisks, no bullet points, no dashes. No preamble. Output only the improved timeline.",
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
