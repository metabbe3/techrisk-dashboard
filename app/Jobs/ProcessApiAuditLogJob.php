<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ApiAuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessApiAuditLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public string $queue = 'api-audit';

    public function __construct(
        public readonly array $auditData
    ) {
        $this->onQueue('api-audit');
    }

    public function handle(): void
    {
        $channel = config('api-audit.log_channel', 'api_audit_daily');

        // Format for ELK ingestion
        $logEntry = $this->formatForElk($this->auditData);

        // Log to file (JSON format)
        Log::channel($channel)->info('api_audit', $logEntry);

        // Store to database if enabled
        if (config('api-audit.store_to_db', true)) {
            $this->storeToDatabase($this->auditData);
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Log failure but don't fail the API request
        Log::error('Failed to process API audit log', [
            'error' => $exception->getMessage(),
            'trace_id' => $this->auditData['trace_id'] ?? 'unknown',
        ]);
    }

    private function formatForElk(array $data): array
    {
        return [
            '@timestamp' => $data['request_timestamp'] ?? now()->toIso8601String(),
            '@version' => '1',

            // ELK index hints
            'index' => [
                'name' => $this->getIndexName(),
            ],

            // Message (for compatibility)
            'message' => sprintf(
                '%s %s - %s',
                $data['method'] ?? 'UNKNOWN',
                $data['path'] ?? '/',
                $data['response_status'] ?? 0
            ),

            // Core fields
            'trace_id' => $data['trace_id'] ?? null,
            'request_id' => $data['request_id'] ?? null,

            // Request fields
            'request' => [
                'timestamp' => $data['request_timestamp'] ?? null,
                'method' => $data['method'] ?? null,
                'endpoint' => $data['endpoint'] ?? null,
                'path' => $data['path'] ?? null,
                'query_params' => $data['query_params'] ?? [],
                'body' => $data['request_body'] ?? null,
                'headers' => $data['request_headers'] ?? [],
            ],

            // User fields
            'user' => [
                'id' => $data['user_id'] ?? null,
                'email' => $data['user_email'] ?? null,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
            ],

            // Response fields
            'response' => [
                'timestamp' => $data['response_timestamp'] ?? null,
                'status' => $data['response_status'] ?? null,
                'time_ms' => $data['response_time_ms'] ?? null,
                'size_bytes' => $data['response_size_bytes'] ?? null,
                'data' => $data['response_data'] ?? null,
            ],

            // Error fields (if applicable)
            'error' => [
                'message' => $data['error_message'] ?? null,
                'exists' => isset($data['error_message']),
            ],

            // Metadata
            'metadata' => $data['metadata'] ?? [],
            'tags' => [
                'api',
                'audit',
                config('app.env'),
            ],
        ];
    }

    private function getIndexName(): string
    {
        $env = config('app.env');
        $date = now()->format('Y.m.d');

        return "api-audit-{$env}-{$date}";
    }

    private function storeToDatabase(array $data): void
    {
        // Only store essential data to database
        ApiAuditLog::create([
            'trace_id' => $data['trace_id'],
            'request_id' => $data['request_id'],
            'request_timestamp' => $data['request_timestamp'],
            'method' => $data['method'],
            'endpoint' => $data['endpoint'],
            'query_params' => $data['query_params'],
            'request_body' => $data['request_body'],
            'user_id' => $data['user_id'],
            'user_email' => $data['user_email'],
            'ip_address' => $data['ip_address'],
            'user_agent' => $data['user_agent'],
            'response_timestamp' => $data['response_timestamp'],
            'response_status' => $data['response_status'],
            'response_time_ms' => $data['response_time_ms'],
            'response_size_bytes' => $data['response_size_bytes'],
            'response_data' => $data['response_data'],
            'error_message' => $data['error_message'] ?? null,
            'environment' => $data['metadata']['environment'] ?? config('app.env'),
            'app_version' => $data['metadata']['app_version'] ?? null,
            'metadata' => $data['metadata'],
        ]);
    }
}
