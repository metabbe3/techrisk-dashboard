<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Concerns\NormalizesUsage;
use App\Services\Ai\Concerns\StripsThinkingTags;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class SseStreamingService
{
    use NormalizesUsage;

    /**
     * Stream an SSE completion from the AI API.
     *
     * @param  string  $baseUrl  API base URL
     * @param  string  $apiKey  API key
     * @param  array  $payload  Request payload (model, messages, max_tokens, stream: true, etc.)
     * @param  int  $timeout  Curl timeout in seconds
     * @param  callable|null  $onDelta  Called with each filtered content delta: fn(string $delta, int $totalLength) => void
     * @param  callable|null  $onComplete  Called when stream ends: fn(array $result) => void
     * @return array{content: string, usage: array, finish_reason: ?string, raw_body: string, http_code: int, response_time_ms: int, error: ?string, tool_calls: array, reasoning_content: ?string}
     *
     * @throws ConnectionException When curl fails to connect
     */
    public function stream(
        string $baseUrl,
        string $apiKey,
        array $payload,
        int $timeout,
        ?callable $onDelta = null,
        ?callable $onComplete = null,
    ): array {
        $fullContent = '';
        $usage = [];
        $finishReason = null;
        $rawBody = '';
        $reasoningContent = null;
        $accumulatedToolCalls = [];
        $lineBuffer = '';
        $thinkingFilter = new StripsThinkingTags;
        $startTime = microtime(true);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($baseUrl, '/').'/chat/completions',
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$apiKey,
                'Content-Type: application/json',
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                &$fullContent, &$usage, &$finishReason, &$rawBody,
                &$accumulatedToolCalls, &$reasoningContent,
                &$lineBuffer, $onDelta, &$thinkingFilter
            ) {
                $lineBuffer .= $data;

                while (($pos = strpos($lineBuffer, "\n")) !== false) {
                    $line = substr($lineBuffer, 0, $pos);
                    $lineBuffer = substr($lineBuffer, $pos + 1);
                    $line = trim($line);

                    if ($line === '' || str_starts_with($line, 'event:') || str_starts_with($line, 'id:')) {
                        continue;
                    }

                    if (! str_starts_with($line, 'data: ')) {
                        $rawBody .= $line."\n";

                        continue;
                    }

                    $json = substr($line, 6);
                    if ($json === '[DONE]') {
                        return strlen($data);
                    }

                    $parsed = json_decode($json, true);
                    if (! $parsed) {
                        continue;
                    }

                    $choice = $parsed['choices'][0] ?? [];
                    $delta = $choice['delta'] ?? [];

                    $textDelta = $delta['content'] ?? '';
                    if ($textDelta !== '') {
                        $filtered = $thinkingFilter->filter($textDelta);
                        if ($filtered !== '') {
                            $fullContent .= $filtered;
                            if ($onDelta) {
                                $onDelta($filtered, strlen($fullContent));
                            }
                        }
                    }

                    $reasoningDelta = $delta['reasoning_content'] ?? null;
                    if ($reasoningDelta !== null) {
                        $reasoningContent = ($reasoningContent ?? '').$reasoningDelta;
                    }

                    $deltaToolCalls = $delta['tool_calls'] ?? [];
                    foreach ($deltaToolCalls as $tc) {
                        $idx = $tc['index'] ?? 0;
                        if (! isset($accumulatedToolCalls[$idx])) {
                            $accumulatedToolCalls[$idx] = [
                                'id' => $tc['id'] ?? '',
                                'type' => $tc['type'] ?? 'function',
                                'function' => ['name' => '', 'arguments' => ''],
                            ];
                        }
                        if (isset($tc['id']) && $tc['id']) {
                            $accumulatedToolCalls[$idx]['id'] = $tc['id'];
                        }
                        if (isset($tc['function']['name'])) {
                            $accumulatedToolCalls[$idx]['function']['name'] .= $tc['function']['name'];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $accumulatedToolCalls[$idx]['function']['arguments'] .= $tc['function']['arguments'];
                        }
                    }

                    $fr = $choice['finish_reason'] ?? null;
                    if ($fr !== null && $fr !== '') {
                        $finishReason = $fr;
                    }

                    if (isset($parsed['usage'])) {
                        $usage = $this->normalizeUsage($parsed['usage']);
                    }
                }

                return strlen($data);
            },
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

        $flushed = $thinkingFilter->flush();
        if ($flushed !== '') {
            $fullContent .= $flushed;
            if ($onDelta) {
                $onDelta($flushed, strlen($fullContent));
            }
        }

        if (! $success) {
            throw new ConnectionException('AI streaming connection failed: '.($curlError ?: 'Unknown error'));
        }

        if ($httpCode >= 400) {
            $errorMsg = 'AI service error (HTTP '.$httpCode.')';
            if (! blank($rawBody)) {
                $errorData = json_decode($rawBody, true);
                $errorMsg = $errorData['error']['message'] ?? $errorMsg;
            }

            $result = $this->buildResult($fullContent, $usage, $finishReason, $rawBody, $httpCode, $responseTimeMs, $accumulatedToolCalls, $reasoningContent, $errorMsg);

            if ($onComplete) {
                $onComplete($result);
            }

            return $result;
        }

        // Flush remaining line buffer
        if (! blank($lineBuffer)) {
            $line = trim($lineBuffer);
            if (str_starts_with($line, 'data: ')) {
                $json = substr($line, 6);
                if ($json !== '[DONE]') {
                    $parsed = json_decode($json, true);
                    if ($parsed) {
                        $delta = $parsed['choices'][0]['delta'] ?? [];
                        $textDelta = $delta['content'] ?? '';
                        if ($textDelta !== '') {
                            $filtered = $thinkingFilter->filter($textDelta);
                            if ($filtered !== '') {
                                $fullContent .= $filtered;
                                if ($onDelta) {
                                    $onDelta($filtered, strlen($fullContent));
                                }
                            }
                        }
                        if (isset($parsed['usage'])) {
                            $usage = $this->normalizeUsage($parsed['usage']);
                        }
                        $fr = $parsed['choices'][0]['finish_reason'] ?? null;
                        if ($fr !== null && $fr !== '') {
                            $finishReason = $fr;
                        }
                    }
                }
            } else {
                $rawBody .= $line;
            }
        }

        // Fallback: non-SSE JSON parsing
        if (blank($fullContent) && empty($accumulatedToolCalls) && ! blank($rawBody)) {
            Log::debug('[SseStreaming] Non-SSE response detected, falling back to JSON parsing');
            $parsed = json_decode($rawBody, true);
            if ($parsed) {
                $responseMessage = $parsed['choices'][0]['message'] ?? [];
                $rawContent = $responseMessage['content'] ?? '';
                $fullContent = StripsThinkingTags::stripStatic($rawContent);
                $finishReason = $parsed['choices'][0]['finish_reason'] ?? 'stop';
                $usage = isset($parsed['usage']) ? $this->normalizeUsage($parsed['usage']) : $usage;
                $accumulatedToolCalls = $responseMessage['tool_calls'] ?? [];
                if ($onDelta && ! blank($fullContent)) {
                    $onDelta($fullContent, strlen($fullContent));
                }
            }
        }

        $result = $this->buildResult($fullContent, $usage, $finishReason, $rawBody, $httpCode, $responseTimeMs, $accumulatedToolCalls, $reasoningContent, null);

        if ($onComplete) {
            $onComplete($result);
        }

        return $result;
    }

    private function buildResult(
        string $fullContent,
        array $usage,
        ?string $finishReason,
        string $rawBody,
        int $httpCode,
        int $responseTimeMs,
        array $accumulatedToolCalls,
        ?string $reasoningContent,
        ?string $error,
    ): array {
        return [
            'content' => $fullContent,
            'usage' => $usage,
            'finish_reason' => $finishReason ?? 'stop',
            'raw_body' => $rawBody,
            'http_code' => $httpCode,
            'response_time_ms' => $responseTimeMs,
            'error' => $error,
            'tool_calls' => array_values($accumulatedToolCalls),
            'reasoning_content' => $reasoningContent,
        ];
    }
}
