<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\WarRoomAgentConfig;
use App\Services\Ai\Concerns\StripsThinkingTags;

class PersonaStreamingService
{
    /**
     * Stream multiple AI persona responses concurrently using curl_multi.
     *
     * @param  iterable<WarRoomAgentConfig>  $personas
     * @param  callable(string $event, array $data): void  $emitSse
     * @return array<string, array> Results keyed by persona role_key
     */
    public function streamConcurrent(
        iterable $personas,
        string $baseUrl,
        string $apiKey,
        ?string $defaultModel,
        array $history,
        string $userMessage,
        int $maxTokens,
        int $timeout,
        ?array $rawAttachments,
        callable $emitSse,
        ChatContextService $contextService,
        ?array $referencedIds = [],
    ): array {
        $personaList = is_array($personas) ? $personas : iterator_to_array($personas);
        $lastKey = collect($personaList)->last()->role_key;

        // Emit all persona_start events upfront
        foreach ($personaList as $index => $persona) {
            $emitSse('persona_start', [
                'persona' => [
                    'key' => $persona->role_key,
                    'name' => $persona->display_name,
                    'icon' => $persona->icon,
                    'color' => $persona->color,
                ],
            ]);
        }

        // Initialize per-persona state and curl handles
        $mh = curl_multi_init();
        $state = [];
        $handleMap = [];

        foreach ($personaList as $index => $persona) {
            $key = $persona->role_key;
            $resolvedModel = app(ModelRouter::class)->pick('smart', $persona->model_override ?? $defaultModel ?? config('ai.default_model', 'SMART-MODEL'));
            $systemPrompt = $contextService->buildPersonaSystemPrompt($persona, $userMessage, $referencedIds ?? []);

            $apiMessages = [['role' => 'system', 'content' => $systemPrompt]];
            $maxHistory = config('ai.chat_max_history', 20);
            $historyMessages = array_slice($history, -$maxHistory);
            foreach ($historyMessages as $msg) {
                $apiMessages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }

            for ($i = count($apiMessages) - 1; $i >= 1; $i--) {
                if (($apiMessages[$i]['role'] ?? '') === 'user') {
                    if (! empty($rawAttachments)) {
                        $attachmentService = app(ChatAttachmentService::class);
                        $apiMessages[$i]['content'] = $attachmentService->buildMessageContent($userMessage, $rawAttachments);
                    } else {
                        $apiMessages[$i]['content'] = $userMessage;
                    }
                    break;
                }
            }

            $state[$key] = [
                'persona' => $persona,
                'model' => $resolvedModel,
                'fullContent' => '',
                'reasoningContent' => '',
                'usage' => [],
                'finishReason' => null,
                'rawBody' => '',
                'lineBuffer' => '',
                'httpCode' => null,
                'curlError' => null,
                'startTime' => microtime(true),
                'thinkingFilter' => new StripsThinkingTags,
                'sortIndex' => $index,
                'isLast' => $key === $lastKey,
                'completed' => false,
                'failed' => false,
                'doneEmitted' => false,
            ];

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => rtrim($baseUrl, '/').'/chat/completions',
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$apiKey,
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => $resolvedModel,
                    'messages' => $apiMessages,
                    'max_tokens' => $maxTokens,
                    'stream' => true,
                ]),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_WRITEFUNCTION => $this->createWriteFunction($key, $state, $emitSse),
            ]);

            curl_multi_add_handle($mh, $ch);
            $handleMap[(int) $ch] = $key;
        }

        // Run the curl_multi loop
        $active = null;
        do {
            $status = curl_multi_exec($mh, $active);
            if ($status !== CURLM_OK) {
                foreach ($state as &$s) {
                    if (! $s['completed']) {
                        $s['failed'] = true;
                        $s['curlError'] = 'curl_multi_exec error (code '.$status.')';
                        $s['completed'] = true;
                    }
                }
                unset($s);
                break;
            }

            // Process completed handles
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch = $info['handle'];
                $handleId = (int) $ch;
                if (! isset($handleMap[$handleId])) {
                    continue;
                }
                $key = $handleMap[$handleId];

                if ($info['result'] !== CURLE_OK) {
                    $state[$key]['curlError'] = curl_error($ch) ?: 'curl error code '.$info['result'];
                    $state[$key]['failed'] = true;
                    $state[$key]['completed'] = true;
                } else {
                    $state[$key]['httpCode'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $state[$key]['completed'] = true;

                    // Flush thinking filter
                    $flushed = $state[$key]['thinkingFilter']->flush();
                    if ($flushed !== '') {
                        $state[$key]['fullContent'] .= $flushed;
                        $emitSse('delta', [
                            'delta' => $flushed,
                            'persona_key' => $key,
                        ]);
                    }

                    // Flush remaining line buffer
                    $this->flushLineBuffer($state[$key], $emitSse);
                }
            }

            if ($active > 0) {
                curl_multi_select($mh, 0.1);
            }
        } while ($active > 0);

        // Cleanup handles
        foreach ($handleMap as $handleId => $key) {
            // We can't reconstruct the handle from the int ID, but curl_multi_cleanup handles it
        }
        curl_multi_close($mh);

        // Build results
        $results = [];
        foreach ($state as $key => &$s) {
            $responseTimeMs = (int) ((microtime(true) - $s['startTime']) * 1000);

            if ($s['failed']) {
                $results[$key] = [
                    'failed' => true,
                    'error' => $s['curlError'] ?: 'Unknown streaming error',
                    'responseTimeMs' => $responseTimeMs,
                    'persona' => $s['persona'],
                    'model' => $s['model'],
                ];

                continue;
            }

            $httpCode = $s['httpCode'];
            if ($httpCode >= 400) {
                $errorMsg = 'AI service error (HTTP '.$httpCode.')';
                if (! blank($s['rawBody'])) {
                    $errorData = json_decode($s['rawBody'], true);
                    $errorMsg = $errorData['error']['message'] ?? $errorMsg;
                }
                $results[$key] = [
                    'failed' => true,
                    'error' => $errorMsg,
                    'responseTimeMs' => $responseTimeMs,
                    'persona' => $s['persona'],
                    'model' => $s['model'],
                ];

                continue;
            }

            // Non-SSE JSON fallback
            if (blank($s['fullContent']) && ! blank($s['rawBody'])) {
                $parsed = json_decode($s['rawBody'], true);
                if ($parsed) {
                    $rawContent = $parsed['choices'][0]['message']['content'] ?? '';
                    $s['fullContent'] = StripsThinkingTags::stripStatic($rawContent);
                    $s['finishReason'] = $parsed['choices'][0]['finish_reason'] ?? 'stop';
                    $s['usage'] = $parsed['usage'] ?? $s['usage'];
                }
            }

            $results[$key] = [
                'failed' => false,
                'fullContent' => $s['fullContent'],
                'reasoningContent' => $s['reasoningContent'],
                'usage' => $s['usage'],
                'finishReason' => $s['finishReason'],
                'responseTimeMs' => $responseTimeMs,
                'httpCode' => $httpCode,
                'rawBody' => $s['rawBody'],
                'persona' => $s['persona'],
                'model' => $s['model'],
                'sortIndex' => $s['sortIndex'],
                'isLast' => $s['isLast'],
            ];
        }

        return $results;
    }

    /**
     * Create a WRITEFUNCTION callback for a specific persona's curl handle.
     */
    private function createWriteFunction(string $key, array &$state, callable $emitSse): \Closure
    {
        return function ($ch, $data) use ($key, &$state, $emitSse) {
            $s = &$state[$key];
            $s['lineBuffer'] .= $data;

            while (($pos = strpos($s['lineBuffer'], "\n")) !== false) {
                $line = substr($s['lineBuffer'], 0, $pos);
                $s['lineBuffer'] = substr($s['lineBuffer'], $pos + 1);
                $line = trim($line);

                if ($line === '' || str_starts_with($line, 'event:') || str_starts_with($line, 'id:')) {
                    continue;
                }

                if (! str_starts_with($line, 'data: ')) {
                    $s['rawBody'] .= $line."\n";

                    continue;
                }

                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    if (! $s['doneEmitted']) {
                        $s['doneEmitted'] = true;
                        $emitSse('persona_done', ['persona_key' => $key]);
                    }

                    return strlen($data);
                }

                $parsed = json_decode($json, true);
                if (! $parsed) {
                    continue;
                }

                $delta = $parsed['choices'][0]['delta']['content'] ?? '';
                $reasoningDelta = $parsed['choices'][0]['delta']['reasoning_content'] ?? '';
                if ($reasoningDelta !== '') {
                    $s['reasoningContent'] .= $reasoningDelta;
                }
                if ($delta !== '') {
                    $filtered = $s['thinkingFilter']->filter($delta);
                    if ($filtered !== '') {
                        $s['fullContent'] .= $filtered;
                        $emitSse('delta', [
                            'delta' => $filtered,
                            'persona_key' => $key,
                        ]);
                    }
                }

                if (isset($parsed['usage'])) {
                    $s['usage'] = $parsed['usage'];
                }

                $fr = $parsed['choices'][0]['finish_reason'] ?? null;
                if ($fr !== null) {
                    $s['finishReason'] = $fr;
                }
            }

            return strlen($data);
        };
    }

    /**
     * Flush any remaining content in the line buffer after curl completes.
     */
    private function flushLineBuffer(array &$s, callable $emitSse): void
    {
        if (blank($s['lineBuffer'])) {
            return;
        }

        $line = trim($s['lineBuffer']);
        $s['lineBuffer'] = '';

        if ($line === '' || ! str_starts_with($line, 'data: ')) {
            if ($line !== '' && ! str_starts_with($line, 'event:') && ! str_starts_with($line, 'id:')) {
                $s['rawBody'] .= $line."\n";
            }

            return;
        }

        $json = substr($line, 6);
        if ($json === '[DONE]') {
            if (! $s['doneEmitted']) {
                $s['doneEmitted'] = true;
                $emitSse('persona_done', ['persona_key' => $s['persona']->role_key]);
            }

            return;
        }

        $parsed = json_decode($json, true);
        if (! $parsed) {
            return;
        }

        $delta = $parsed['choices'][0]['delta']['content'] ?? '';
        if ($delta !== '') {
            $filtered = $s['thinkingFilter']->filter($delta);
            if ($filtered !== '') {
                $s['fullContent'] .= $filtered;
                $emitSse('delta', [
                    'delta' => $filtered,
                    'persona_key' => $s['persona']->role_key,
                ]);
            }
        }

        if (isset($parsed['usage'])) {
            $s['usage'] = $parsed['usage'];
        }

        $fr = $parsed['choices'][0]['finish_reason'] ?? null;
        if ($fr !== null) {
            $s['finishReason'] = $fr;
        }
    }
}
