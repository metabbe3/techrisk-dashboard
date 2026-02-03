<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DTO\ApiAuditLogEntry;
use App\Jobs\ProcessApiAuditLogJob;
use App\Services\SensitiveDataFilter;
use App\Services\TraceIdService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApiAuditLogger
{
    private const SKIP_ENDPOINTS = [
        'health',
        'metrics',
        'ping',
    ];

    private const MAX_BODY_SIZE = 10240; // 10KB

    public function __construct(
        private readonly TraceIdService $traceIdService,
        private readonly SensitiveDataFilter $dataFilter
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        // Skip logging for health check endpoints
        if ($this->shouldSkipLogging($request)) {
            return $next($request);
        }

        // Generate or retrieve trace ID
        $traceId = $this->traceIdService->getOrCreateTraceId($request);
        $request->headers->set('X-Trace-ID', $traceId);

        // Capture request data
        $auditEntry = $this->captureRequestData($request, $traceId);

        // Process the request
        $response = $next($request);

        // Capture response data
        $this->captureResponseData($response, $auditEntry, $startTime);

        // Add trace ID to response headers
        $response->headers->set('X-Trace-ID', $traceId);

        // Dispatch logging job asynchronously
        $this->dispatchAuditLog($auditEntry);

        return $response;
    }

    private function shouldSkipLogging(Request $request): bool
    {
        $path = $request->path();

        return in_array($path, self::SKIP_ENDPOINTS) ||
               str_starts_with($path, 'health') ||
               str_starts_with($path, 'metrics');
    }

    private function captureRequestData(Request $request, string $traceId): ApiAuditLogEntry
    {
        $user = $request->user();

        return new ApiAuditLogEntry(
            trace_id: $traceId,
            request_id: $this->traceIdService->generateRequestId(),
            request_timestamp: now()->toIso8601String(),
            user_id: $user?->id,
            user_email: $user?->email,
            ip_address: $request->ip(),
            user_agent: $request->userAgent(),
            method: $request->method(),
            endpoint: $request->fullUrl(),
            path: $request->path(),
            query_params: $this->filterSensitiveData(
                $request->query->all()
            ),
            request_body: $this->captureRequestBody($request),
            request_headers: $this->captureRequestHeaders($request),
        );
    }

    private function captureRequestBody(Request $request): ?array
    {
        if (!$request->isMethod('POST', 'PUT', 'PATCH')) {
            return null;
        }

        $body = $request->input();

        // Filter sensitive data
        $filtered = $this->filterSensitiveData($body);

        // Truncate if too large
        if (strlen((string) json_encode($filtered)) > self::MAX_BODY_SIZE) {
            return ['_truncated' => true, '_size' => strlen((string) json_encode($body))];
        }

        return $filtered;
    }

    private function captureRequestHeaders(Request $request): array
    {
        $allowedHeaders = [
            'content-type',
            'accept',
            'accept-language',
            'authorization', // Will be filtered
        ];

        $headers = [];
        foreach ($allowedHeaders as $header) {
            if ($request->hasHeader($header)) {
                $headers[$header] = $this->filterHeaderValue(
                    $header,
                    $request->header($header)
                );
            }
        }

        return $headers;
    }

    private function captureResponseData(
        Response $response,
        ApiAuditLogEntry $entry,
        float $startTime
    ): void {
        $entry->response_timestamp = now()->toIso8601String();
        $entry->response_status = $response->getStatusCode();
        $entry->response_time_ms = (int) ((microtime(true) - $startTime) * 1000);
        $entry->response_size_bytes = strlen((string) $response->getContent());

        if ($response->isClientError() || $response->isServerError()) {
            $entry->error_message = $this->extractErrorMessage($response);
        }

        // Capture response data (non-success only, with size limit)
        if (!$response->isSuccessful()) {
            $entry->response_data = $this->captureResponseContent($response);
        }
    }

    private function captureResponseContent(Response $response): ?array
    {
        $content = $response->getContent();

        if (empty($content)) {
            return null;
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            return ['_raw' => substr($content, 0, 1000)];
        }

        return $this->dataFilter->filter($data);
    }

    private function extractErrorMessage(Response $response): ?string
    {
        $content = $response->getContent();
        $data = json_decode($content, true);

        if (isset($data['message'])) {
            return $data['message'];
        }

        if (isset($data['error'])) {
            return $data['error'];
        }

        return $response->statusText();
    }

    private function filterSensitiveData(array $data): array
    {
        return $this->dataFilter->filter($data);
    }

    private function filterHeaderValue(string $header, string $value): string
    {
        if (in_array(strtolower($header), ['authorization', 'cookie'])) {
            return '[REDACTED]';
        }

        return $value;
    }

    private function dispatchAuditLog(ApiAuditLogEntry $entry): void
    {
        // Add metadata
        $entry->metadata = [
            'environment' => config('app.env'),
            'app_version' => config('app.version', '1.0.0'),
            'server_hostname' => gethostname(),
        ];

        dispatch(new ProcessApiAuditLogJob($entry->toArray()));
    }
}
