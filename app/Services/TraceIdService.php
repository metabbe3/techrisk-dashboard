<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TraceIdService
{
    private const TRACE_ID_HEADER = 'X-Trace-ID';
    private const REQUEST_ID_HEADER = 'X-Request-ID';

    private ?string $currentTraceId = null;

    public function getOrCreateTraceId(Request $request): string
    {
        // Check for existing trace ID in header
        $traceId = $request->header(self::TRACE_ID_HEADER);

        if ($traceId && $this->isValidTraceId($traceId)) {
            return $traceId;
        }

        // Generate new trace ID
        return $this->generateTraceId();
    }

    public function getCurrentTraceId(): ?string
    {
        return $this->currentTraceId;
    }

    public function setCurrentTraceId(string $traceId): void
    {
        $this->currentTraceId = $traceId;
    }

    public function generateTraceId(): string
    {
        // Format: {timestamp}-{server-id}-{random}
        $timestamp = now()->format('YmdHis');
        $serverId = substr((string) gethostname(), 0, 8);
        $random = Str::random(8);

        return sprintf('%s-%s-%s', $timestamp, $serverId, $random);
    }

    public function generateRequestId(): string
    {
        return (string) Str::uuid();
    }

    public function isValidTraceId(string $traceId): bool
    {
        // Validate trace ID format (alphanumeric with hyphens)
        return preg_match('/^[a-zA-Z0-9\-]+$/', $traceId) === 1;
    }

    public function getTraceIdHeaderName(): string
    {
        return self::TRACE_ID_HEADER;
    }

    public function getRequestIdHeaderName(): string
    {
        return self::REQUEST_ID_HEADER;
    }
}
