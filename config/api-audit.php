<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | The logging channel to use for API audit logs.
    |
    */
    'log_channel' => env('API_AUDIT_LOG_CHANNEL', 'api_audit_daily'),

    /*
    |--------------------------------------------------------------------------
    | Log Level
    |--------------------------------------------------------------------------
    |
    | The minimum log level for API audit logs.
    |
    */
    'log_level' => env('API_AUDIT_LOG_LEVEL', 'info'),

    /*
    |--------------------------------------------------------------------------
    | Retention Days
    |--------------------------------------------------------------------------
    |
    | Number of days to retain audit logs.
    |
    */
    'retention_days' => env('API_AUDIT_RETENTION_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Sensitive Field Patterns
    |--------------------------------------------------------------------------
    |
    | Field name patterns that should be redacted in logs.
    |
    */
    'sensitive_fields' => [
        'password',
        'password_confirmation',
        '*token*',
        '*secret*',
        '*key*',
        'credit_card',
        'cvv',
        'ssn',
    ],

    /*
    |--------------------------------------------------------------------------
    | Skip Endpoints
    |--------------------------------------------------------------------------
    |
    | Endpoint patterns that should not be logged.
    |
    */
    'skip_endpoints' => [
        'health*',
        'metrics*',
        'ping*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Response Body
    |--------------------------------------------------------------------------
    |
    | Whether to log response body (only for errors).
    |
    */
    'log_response_body' => env('API_AUDIT_LOG_RESPONSE_BODY', true),

    /*
    |--------------------------------------------------------------------------
    | Max Body Size
    |--------------------------------------------------------------------------
    |
    | Maximum request/response body size to log (in bytes).
    |
    */
    'max_body_size' => env('API_AUDIT_MAX_BODY_SIZE', 10240), // 10KB

    /*
    |--------------------------------------------------------------------------
    | Store to Database
    |--------------------------------------------------------------------------
    |
    | Whether to persist audit logs to database.
    |
    */
    'store_to_db' => env('API_AUDIT_STORE_TO_DB', true),
];
