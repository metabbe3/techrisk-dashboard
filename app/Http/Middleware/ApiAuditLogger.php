<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DTO\ApiAuditLogEntry;
use App\Jobs\ProcessApiAuditLogJob;
use App\Services\SensitiveDataFilter;
use App\Services\TraceIdService;
use Closure;
use Illuminate\Http\Request;
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
        $response = null;

        try {
            // Process the request
            $response = $next($request);

            // Capture response data
            $this->captureResponseData($response, $auditEntry, $startTime);

            // Add trace ID to response headers
            $response->headers->set('X-Trace-ID', $traceId);
        } catch (\Throwable $e) {
            // Failing requests must still be audited. Previously, if $next or
            // captureResponseData threw, dispatchAuditLog was skipped entirely —
            // so 4xx/5xx never appeared in the audit log. Synthesize the right
            // status from the exception type; the finally block always dispatches.
            $auditEntry->response_timestamp = now()->toIso8601String();
            $auditEntry->response_status = $this->statusFromException($e);
            $auditEntry->response_time_ms = (int) ((microtime(true) - $startTime) * 1000);
            $auditEntry->error_message = $e::class.': '.$e->getMessage();

            throw $e;
        } finally {
            // Capture user AFTER auth has run (we run first via middleware
            // priority, so $request->user() is null at captureRequestData time).
            $user = $request->user();
            if ($user && ! $auditEntry->user_id) {
                $auditEntry->user_id = $user->id;
                $auditEntry->user_email = $user->email;
            }

            // Always dispatch the audit log — success or failure.
            $this->dispatchAuditLog($auditEntry);
        }

        return $response;
    }

    /**
     * Map an exception to its HTTP status so the audit log records the real code.
     */
    private function statusFromException(\Throwable $e): int
    {
        return match (true) {
            $e instanceof \Illuminate\Auth\AuthenticationException => 401,
            $e instanceof \Illuminate\Auth\Access\AuthorizationException => 403,
            $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException,
            $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException => 404,
            $e instanceof \Illuminate\Validation\ValidationException => 422,
            $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException => $e->getStatusCode(),
            default => 500,
        };
    }

    private function shouldSkipLogging(Request $request): bool
    {
        $path = $request->path();

        $stripped = str_starts_with($path, 'api/') ? substr($path, 4) : $path;

        return in_array($stripped, self::SKIP_ENDPOINTS) ||
               str_starts_with($stripped, 'health') ||
               str_starts_with($stripped, 'metrics');
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
        if (! $request->isMethod('POST', 'PUT', 'PATCH')) {
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
        $entry->response_headers = $this->captureResponseHeaders($response);

        $content = $response->getContent();
        $entry->response_size_bytes = strlen($content);
        $decoded = json_decode($content, true);

        if ($response->isClientError() || $response->isServerError()) {
            $entry->error_message = $this->extractErrorMessage($decoded) ?? $response->statusText();
        }

        $entry->response_data = $this->captureResponseContent($decoded, $content);
    }

    private function captureResponseContent(?array $decoded, string $rawContent): ?array
    {
        if (empty($rawContent)) {
            return null;
        }

        if (! is_array($decoded)) {
            return ['_raw' => substr($rawContent, 0, 1000)];
        }

        return $this->dataFilter->filter($decoded);
    }

    private function extractErrorMessage(?array $data): ?string
    {
        if (! $data) {
            return null;
        }

        if (isset($data['message'])) {
            return is_string($data['message']) ? $data['message'] : json_encode($data['message']);
        }

        if (isset($data['error'])) {
            return $data['error'];
        }

        return null;
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

    private function captureResponseHeaders(Response $response): array
    {
        $allowedHeaders = [
            'content-type',
            'x-trace-id',
            'cache-control',
            'x-ratelimit-limit',
            'x-ratelimit-remaining',
        ];

        $headers = [];
        foreach ($allowedHeaders as $header) {
            if ($response->headers->has($header)) {
                $headers[$header] = $response->headers->get($header);
            }
        }

        return $headers;
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
