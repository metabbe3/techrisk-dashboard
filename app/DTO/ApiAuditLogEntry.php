<?php

declare(strict_types=1);

namespace App\DTO;

class ApiAuditLogEntry
{
    public function __construct(
        public ?string $trace_id = null,
        public ?string $request_id = null,
        public ?string $request_timestamp = null,
        public ?int $user_id = null,
        public ?string $user_email = null,
        public ?string $ip_address = null,
        public ?string $user_agent = null,
        public ?string $method = null,
        public ?string $endpoint = null,
        public ?string $path = null,
        public ?array $query_params = null,
        public ?array $request_body = null,
        public ?array $request_headers = null,
        public ?string $response_timestamp = null,
        public ?int $response_status = null,
        public ?int $response_time_ms = null,
        public ?int $response_size_bytes = null,
        public ?array $response_data = null,
        public ?string $error_message = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Convert audit entry to array
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->trace_id,
            'request_id' => $this->request_id,
            'request_timestamp' => $this->request_timestamp,
            'user_id' => $this->user_id,
            'user_email' => $this->user_email,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'method' => $this->method,
            'endpoint' => $this->endpoint,
            'path' => $this->path,
            'query_params' => $this->query_params,
            'request_body' => $this->request_body,
            'request_headers' => $this->request_headers,
            'response_timestamp' => $this->response_timestamp,
            'response_status' => $this->response_status,
            'response_time_ms' => $this->response_time_ms,
            'response_size_bytes' => $this->response_size_bytes,
            'response_data' => $this->response_data,
            'error_message' => $this->error_message,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Fill data from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            trace_id: $data['trace_id'] ?? null,
            request_id: $data['request_id'] ?? null,
            request_timestamp: $data['request_timestamp'] ?? null,
            user_id: $data['user_id'] ?? null,
            user_email: $data['user_email'] ?? null,
            ip_address: $data['ip_address'] ?? null,
            user_agent: $data['user_agent'] ?? null,
            method: $data['method'] ?? null,
            endpoint: $data['endpoint'] ?? null,
            path: $data['path'] ?? null,
            query_params: $data['query_params'] ?? null,
            request_body: $data['request_body'] ?? null,
            request_headers: $data['request_headers'] ?? null,
            response_timestamp: $data['response_timestamp'] ?? null,
            response_status: $data['response_status'] ?? null,
            response_time_ms: $data['response_time_ms'] ?? null,
            response_size_bytes: $data['response_size_bytes'] ?? null,
            response_data: $data['response_data'] ?? null,
            error_message: $data['error_message'] ?? null,
            metadata: $data['metadata'] ?? null,
        );
    }
}
