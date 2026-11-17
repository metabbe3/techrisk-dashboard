<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Synchronous client for the DingTalk agent's brain — the same Go tool loop
 * that answers chat turns and scheduled runs, called directly (mode "task",
 * unbound — no DingTalk delivery) for dashboard features that need one smart
 * generation without an AiAgent row (investigation-form intake).
 * Owner 2026-11-16: use the agent brain for the intake's AI extraction.
 */
class GoAgentBrain
{
    public const FIELD_TYPE_EXTRACT = 'investigation_form_extract';

    public const FIELD_TYPE_VERIFY = 'investigation_form_verify';

    /** Large JSON extraction payloads exceed the chat-sized default (8000). */
    private const EXTRACTION_REPLY_CAP = 60000;

    public function runTask(string $system, string $userMessage, string $fieldType): AiTextResult
    {
        $token = (string) config('ai.go_agent.token');

        if ($token === '') {
            return AiTextResult::failure('Go agent not configured — set GO_AGENT_INTERNAL_TOKEN.');
        }

        $payload = [
            'run_id' => (string) Str::uuid(),
            'agent_id' => 'investigation-form-intake',
            'agent_name' => 'Investigation Form Intake',
            'mode' => 'task',
            'text' => mb_substr($userMessage, 0, 96000),
        ];

        if ($system !== '') {
            $payload['system'] = Str::limit($system, 60000);
        }

        if ($replyCap = self::replyCap($fieldType)) {
            $payload['max_reply_chars'] = $replyCap;
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders(['X-Agent-Token' => $token])
                ->timeout((int) config('ai.go_agent.timeout', 420))
                ->post(rtrim((string) config('ai.go_agent.base_url'), '/').'/internal/run', $payload);
        } catch (\Throwable $e) {
            return $this->fail($fieldType, 'Go agent unreachable: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            return $this->fail($fieldType, 'Go agent rejected the request — X-Agent-Token mismatch (401).');
        }

        if (! $response->successful()) {
            return $this->fail($fieldType, 'Go agent HTTP '.$response->status().': '.Str::limit((string) $response->body(), 500));
        }

        $data = $response->json();

        if (! is_array($data) || ($data['ok'] ?? null) !== true) {
            return $this->fail($fieldType, 'Go agent error: '.Str::limit((string) ($data['error'] ?? 'malformed response'), 500));
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        $result = AiTextResult::success(
            (string) ($data['output'] ?? ''),
            (string) ($data['model'] ?? ''),
            isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
            isset($data['response_time_ms']) ? (float) $data['response_time_ms'] : (microtime(true) - $startedAt) * 1000.0,
        );

        $this->log($fieldType, $result->model, $result);

        return $result;
    }

    /**
     * One task-mode run whose output must parse as JSON. Same contract as the
     * go path in RunAiAgentJob: one repair re-POST feeding the parse error
     * back, then give up.
     *
     * @return array{result: AiTextResult, data: ?array<string, mixed>}
     */
    public function runTaskForJson(string $system, string $userMessage, string $fieldType): array
    {
        $result = $this->runTask($system, $userMessage, $fieldType);

        $data = $result->success ? $this->decode($result->text) : null;

        if ($result->success && $data === null) {
            $repair = $userMessage."\n\n## Your previous response was not valid JSON (parse error: "
                .json_last_error_msg().'). Respond again with valid JSON only.';
            $result = $this->runTask($system, $repair, $fieldType);
            $data = $result->success ? $this->decode($result->text) : null;
        }

        return ['result' => $result, 'data' => $data];
    }

    private function decode(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $decoded = json_decode(trim($text), true);

        // Tolerate fenced/prose-wrapped JSON — the Go reply filter and the
        // model itself can surround the object ("Here you go: ```json …").
        if (! is_array($decoded)
            && ($start = mb_strpos($text, '{')) !== false
            && ($end = mb_strrpos($text, '}')) !== false
            && $end > $start) {
            $decoded = json_decode(mb_substr($text, $start, $end - $start + 1), true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    private static function replyCap(string $fieldType): int
    {
        return $fieldType === self::FIELD_TYPE_EXTRACT ? self::EXTRACTION_REPLY_CAP : 0;
    }

    private function fail(string $fieldType, string $error): AiTextResult
    {
        $result = AiTextResult::failure($error);
        $this->log($fieldType, null, $result);

        return $result;
    }

    private function log(string $fieldType, ?string $model, AiTextResult $result): void
    {
        app(AiUsageLogger::class)->logFromResult(
            fieldType: $fieldType,
            model: $model,
            result: $result,
            metadata: ['engine' => 'go'],
        );
    }
}
