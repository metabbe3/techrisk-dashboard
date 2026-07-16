<?php

namespace App\Services\Ai;

use App\Services\Ai\Concerns\InteractsWithAiApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Embed text via the gateway's /v1/embeddings endpoint (text-embedding-3-large),
 * for semantic (vector) retrieval. Returns a float[] vector or null when disabled
 * or on failure (callers fall back to lexical-only).
 */
class EmbeddingService
{
    use InteractsWithAiApi;

    /**
     * @return float[]|null
     */
    public function embed(string $text): ?array
    {
        if (! config('ai.embeddings.enabled', false)) {
            return null;
        }

        $text = trim($text);
        if ($text === '') {
            return null;
        }

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout(30)
                ->post($this->embedUrl(), [
                    'model' => config('ai.embeddings.model', 'text-embedding-3-large'),
                    'input' => $text,
                ]);

            if (! $response->successful()) {
                Log::warning('[EmbeddingService] embeddings call failed', ['status' => $response->status()]);

                return null;
            }

            $vector = $response->json('data.0.embedding');

            return is_array($vector) ? array_map('floatval', $vector) : null;
        } catch (\Throwable $e) {
            Log::warning('[EmbeddingService] embeddings call errored', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function embedUrl(): string
    {
        return rtrim((string) $this->getBaseUrl(), '/').config('ai.embeddings.endpoint', '/v1/embeddings');
    }
}
