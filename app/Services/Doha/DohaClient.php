<?php

declare(strict_types=1);

namespace App\Services\Doha;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Read-only HTTP client for the DOHA fund-loss API (contract v1 —
 * doha_temp/DOHA API Integration Guide.pdf).
 *
 * Branch on the HTTP STATUS only, never on body wording (the guide says the
 * wording may change). Retry 5xx with backoff; 4xx and 4xx-only errors are
 * terminal — a person must fix something. Error bodies are logged truncated,
 * never the Authorization header.
 */
class DohaClient
{
    /** Setting key — value stored Crypt-encrypted at rest. */
    public const TOKEN_KEY = 'doha_api_token';

    public const BASE_URL = 'https://doha.dana.id';

    public const PAGE_SIZE = 500;

    public function token(): ?string
    {
        $stored = Setting::get(self::TOKEN_KEY);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    public function configured(): bool
    {
        return $this->token() !== null;
    }

    /**
     * One page of DOHA incidents. `updatedSince` is sent verbatim (the
     * watermark is DOHA's own string — round-trip it byte-identically).
     *
     * @return array{status: int, rows: list<array<string, mixed>>, error: ?string}
     */
    public function incidents(?string $updatedSince = null, int $offset = 0, ?int $limit = null): array
    {
        return $this->request('/api/incidents', array_filter([
            'limit' => $limit ?? self::PAGE_SIZE,
            'offset' => $offset,
            'updatedSince' => $updatedSince,
        ], static fn ($value) => $value !== null));
    }

    /**
     * Tombstones: [{id, deletedAt}] for incidents deleted at/after $since.
     *
     * @return array{status: int, rows: list<array<string, mixed>>, error: ?string}
     */
    public function deleted(?string $since, int $offset = 0, ?int $limit = null): array
    {
        return $this->request('/api/incidents/deleted', array_filter([
            'since' => $since,
            'limit' => $limit ?? self::PAGE_SIZE,
            'offset' => $offset,
        ], static fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array{status: int, rows: list<array<string, mixed>>, error: ?string}
     */
    private function request(string $path, array $query): array
    {
        $token = $this->token();

        if ($token === null) {
            return ['status' => 0, 'rows' => [], 'error' => 'No DOHA token configured'];
        }

        $backoff = config('doha.retry_backoff', [1, 5]);
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = Http::withToken($token)
                    ->timeout(30)
                    ->connectTimeout(10)
                    ->get(self::BASE_URL.$path, $query);
            } catch (ConnectionException $exception) {
                Log::warning('DOHA request failed', ['path' => $path, 'error' => $exception->getMessage()]);

                return ['status' => 0, 'rows' => [], 'error' => 'Connection failed: '.$exception->getMessage()];
            }

            $status = $response->status();

            if ($status >= 500 && $attempt <= count($backoff)) {
                sleep((int) $backoff[$attempt - 1]);

                continue;
            }

            if ($status >= 200 && $status < 300) {
                return ['status' => $status, 'rows' => $response->json() ?? [], 'error' => null];
            }

            $body = Str::limit($response->body(), 500);
            Log::warning('DOHA request error', ['path' => $path, 'status' => $status, 'body' => $body]);

            return ['status' => $status, 'rows' => [], 'error' => 'HTTP '.$status.': '.$body];
        }
    }
}
