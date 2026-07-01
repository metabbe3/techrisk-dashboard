<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ApiAuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessApiAuditLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    // Queue is set via constructor ->onQueue()

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

        // Ship to external endpoint if enabled
        if (config('api-audit.external_endpoint_enabled', false)) {
            $this->shipToExternalEndpoint($this->auditData);
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

    private function formatForExternalEndpoint(array $data): array
    {
        return [
            'transaction_metadata' => [
                'request_id' => $data['request_id'] ?? null,
                'correlation_id' => $data['trace_id'] ?? null,
                'timestamp' => $data['request_timestamp'] ?? now()->toIso8601String(),
                'execution_time_ms' => $data['response_time_ms'] ?? null,
            ],
            'request' => [
                'http_method' => $data['method'] ?? null,
                'request_uri' => $data['endpoint'] ?? null,
                'client_ip' => $data['ip_address'] ?? null,
                'request_headers' => $data['request_headers'] ?? [],
                'query_parameters' => $data['query_params'] ?? [],
                'request_body' => $data['request_body'] ?? null,
            ],
            'response' => [
                'http_status_code' => $data['response_status'] ?? null,
                'response_headers' => $data['response_headers'] ?? [],
                'response_body' => $data['response_data'] ?? null,
            ],
        ];
    }

    private function shipToExternalEndpoint(array $data): void
    {
        try {
            $endpoint = config('api-audit.external_endpoint');

            if (empty($endpoint)) {
                return;
            }

            $timeout = config('api-audit.external_endpoint_timeout', 5);
            $apiKey = config('api-audit.external_endpoint_api_key');

            $payload = $this->formatForExternalEndpoint($data);

            $http = Http::timeout($timeout);

            if (! empty($apiKey)) {
                $http = $http->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                ]);
            }

            $response = $http->post($endpoint, $payload);

            if (! $response->successful()) {
                Log::warning('External audit endpoint returned non-success status', [
                    'status' => $response->status(),
                    'trace_id' => $data['trace_id'] ?? 'unknown',
                    'endpoint' => $endpoint,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to ship audit log to external endpoint', [
                'error' => $e->getMessage(),
                'trace_id' => $data['trace_id'] ?? 'unknown',
                'endpoint' => config('api-audit.external_endpoint'),
            ]);
        }
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
                'headers' => $data['response_headers'] ?? [],
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
            'request_headers' => $data['request_headers'] ?? null,
            'user_id' => $data['user_id'],
            'user_email' => $data['user_email'],
            'ip_address' => $data['ip_address'],
            'user_agent' => $data['user_agent'],
            'response_timestamp' => $data['response_timestamp'],
            'response_status' => $data['response_status'],
            'response_time_ms' => $data['response_time_ms'],
            'response_size_bytes' => $data['response_size_bytes'],
            'response_data' => $this->truncateForStorage($data['response_data']),
            'response_headers' => $data['response_headers'],
            'error_message' => $data['error_message'] ?? null,
            'environment' => $data['metadata']['environment'] ?? config('app.env'),
            'app_version' => $data['metadata']['app_version'] ?? null,
            'metadata' => $data['metadata'],
        ]);
    }

    /**
     * Bound storage: cap response payloads so large list/export responses don't
     * bloat the audit table. Mirrors the request-body 10 KB cap in the middleware.
     */
    private function truncateForStorage(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data);

        if ($json === false) {
            return ['_truncated' => true, '_error' => 'json_encode failed'];
        }

        if (strlen($json) > 10240) {
            return ['_truncated' => true, '_size' => strlen($json)];
        }

        return $data;
    }
}
