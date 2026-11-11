<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Enums\AiAgentEngine;
use App\Enums\AiAgentFrequency;
use App\Enums\AiAgentRunStatus;
use App\Models\AiAgent;
use App\Models\AiAgentMemory;
use App\Models\AiAgentRun;
use App\Services\Ai\AiAgentMemoryService;
use App\Services\Ai\AiTextResult;
use App\Services\Ai\AiTextService;
use App\Services\Ai\AiUsageLogger;
use App\Services\Ai\ChatContextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// Agents run on one of three engines (AiAgentEngine):
// - php: single AiTextService call with context blocks stuffed in (the v1 path)
// - go:  the DingTalk Go agent's 60-iteration tool loop over the internal HTTP
//        endpoint — the same assembled context, plus dashboard/Notion/web tools
// - static: no model call at all — the text is delivered as-is via the Go
//        agent's DingTalk channel (message-mode chat reminders)
// Instructions are always prompt text, never eval'd — no local code execution
// in any engine.
class RunAiAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Timeout ladder: Go run ctx 360s < HTTP 420s < this 480s < worker 660s
    // <= redis retry_after 900s; the lock TTL below must exceed this timeout.
    public int $timeout = 480;

    public function __construct(
        public string $agentId,
        public string $runId,
    ) {
        $this->onQueue('default');
    }

    public function handle(AiTextService $ai, ChatContextService $context, AiAgentMemoryService $memory): void
    {
        $agent = AiAgent::find($this->agentId);
        $run = AiAgentRun::find($this->runId);

        if (! $agent || ! $run) {
            return;
        }

        // Lock TTL (900s) > job timeout (480s) so a killed worker self-heals.
        $lock = Cache::lock("ai-agent-run:{$this->agentId}", 900);

        if (! $lock->get()) {
            $run->forceFill([
                'status' => AiAgentRunStatus::Failed->value,
                'error_message' => 'Skipped — another run of this agent is still in progress.',
                'completed_at' => now(),
            ])->save();

            return;
        }

        try {
            $run->forceFill([
                'status' => AiAgentRunStatus::Running->value,
                'started_at' => now(),
            ])->save();

            // Engine branch. generate() resolves model null → AiSetting
            // default_model, and writes the ai_usage_logs row (field_type
            // 'agent') itself for the php path; the go path logs usage below.
            if ($agent->engine === AiAgentEngine::Static) {
                // Static: no model call, no context — the text is delivered
                // as-is via the Go agent's DingTalk channel (message-mode
                // chat reminders).
                $result = $this->runViaGoEngine($agent, $run, null, (string) $run->input, 'message');
            } else {
                [$userMessage, $systemPrompt] = $this->assemblePrompts($agent, $run, $context, $memory);

                $result = $agent->engine === AiAgentEngine::Go
                    ? $this->runGoWithValidation($agent, $run, $systemPrompt, $userMessage)
                    : $this->generateWithRetryAndValidation($ai, $agent, $systemPrompt, $userMessage);
            }

            $run->forceFill([
                'status' => $result->success ? AiAgentRunStatus::Completed->value : AiAgentRunStatus::Failed->value,
                'output' => $result->success ? $result->text : null,
                'model' => $result->model ?? $agent->model,
                'prompt_tokens' => $result->promptTokens,
                'completion_tokens' => $result->completionTokens,
                'total_tokens' => $result->totalTokens,
                'response_time_ms' => $result->responseTimeMs !== null ? (int) $result->responseTimeMs : null,
                'error_message' => $result->success ? null : $result->error,
                'completed_at' => now(),
            ])->save();

            // Go task runs write their own usage row (the php path logs inside
            // AiTextService::generate; static makes no model call — no row).
            // ai_usage_logs.model is NOT NULL: when the endpoint never answered
            // there is no model to name — the engine itself is the honest value.
            if ($agent->engine === AiAgentEngine::Go) {
                try {
                    app(AiUsageLogger::class)->logFromResult(
                        'agent',
                        $result->model ?: ($agent->model ?: 'go-agent'),
                        $result,
                        strlen($systemPrompt ?? '') + strlen($userMessage ?? ''),
                        ['agent_id' => $agent->id, 'agent_name' => $agent->name, 'engine' => 'go'],
                    );
                } catch (\Throwable $e) {
                    Log::warning('Go agent usage logging failed', ['agent' => $agent->id, 'error' => $e->getMessage()]);
                }
            }

            // One-shot lifecycle (owner 2026-11-11: failures stay visible and
            // retryable). Success parks the agent; failure re-arms run_at +5min
            // while under 3 failed runs (this one included), then gives up.
            // Cron agents retry naturally on the next due tick — nothing to do.
            if ($agent->frequency === AiAgentFrequency::Once) {
                if ($result->success) {
                    $agent->forceFill(['enabled' => false])->save();
                } elseif ($agent->runs()->where('status', AiAgentRunStatus::Failed->value)->count() < 3) {
                    $agent->forceFill(['run_at' => now()->addMinutes(5)])->save();
                } else {
                    $agent->forceFill(['enabled' => false])->save();
                }
            }

            if ($result->success && $agent->include_memory) {
                try {
                    foreach ($memory->extractFromOutput((string) $result->text) as $row) {
                        AiAgentMemory::create([
                            'agent_id' => $agent->id,
                            'run_id' => $run->id,
                            'kind' => $row['kind'],
                            'content' => $row['content'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Memory is best-effort; a write failure must never fail the run.
                    Log::warning('AI agent memory extraction failed', ['agent' => $agent->name, 'error' => $e->getMessage()]);
                }
            }

            if ($agent->notify_email) {
                try {
                    Mail::raw($result->success ? (string) $result->text : 'Agent run failed: '.(string) $result->error, function ($message) use ($agent, $result): void {
                        $message->to($agent->notify_email)
                            ->subject("AI Agent '{$agent->name}' — ".($result->success ? 'completed' : 'failed'));
                    });
                } catch (\Throwable $e) {
                    // Delivery is best-effort; a mail outage must never mark the run failed.
                    Log::warning('AI agent run email delivery failed', ['agent' => $agent->name, 'error' => $e->getMessage()]);
                }
            }

            // Chain: a successful completion hands off to the agents that run
            // after this one. Best-effort — a dispatch failure is logged, never thrown.
            if ($result->success) {
                try {
                    AiAgent::query()
                        ->where('depends_on_agent_id', $agent->id)
                        ->where('enabled', true)
                        ->get()
                        ->each(fn (AiAgent $dependent) => $dependent->dispatchRun(triggeredByRunId: $run->id));
                } catch (\Throwable $e) {
                    Log::warning('AI agent chain dispatch failed', ['agent' => $agent->name, 'error' => $e->getMessage()]);
                }
            }
        } finally {
            $lock->release();
        }
    }

    private function buildAttachedFilesBlock(AiAgent $agent, int $budget): string
    {
        $sections = [];

        foreach ($agent->files()->whereNotNull('extracted_text')->orderBy('filename')->get() as $file) {
            if ($budget <= 0) {
                break;
            }

            $sections[] = "### {$file->filename}\n".Str::limit((string) $file->extracted_text, $budget);
            $budget -= min(strlen((string) $file->extracted_text), $budget);
        }

        return $sections === [] ? '' : "## Attached files\n".implode("\n\n", $sections);
    }

    private function buildInvestigationDocsBlock(int $count, int $budget): string
    {
        if (! class_exists(\App\Models\InvestigationDocument::class)) {
            return '';
        }

        $converter = app(\App\Services\Markdown\DocumentConverterService::class);
        $sections = [];

        \App\Models\InvestigationDocument::query()->latest('id')->limit($count)->get()
            ->each(function ($doc) use (&$sections, &$budget, $converter): void {
                if ($budget <= 0) {
                    return;
                }

                try {
                    $markdown = $converter->convert($doc);
                } catch (\Throwable) {
                    return; // unreadable doc — skip, never fail the run
                }

                if (blank($markdown)) {
                    return;
                }

                $sections[] = '### '.($doc->original_filename ?? 'document')."\n".$markdown;
                $budget -= min(strlen((string) $markdown), $budget);
            });

        return $sections === [] ? '' : "## Investigation documents (most recent first)\n".implode("\n\n", $sections);
    }

    /**
     * Generation with two safety nets, side effects still exactly once:
     * 1. One retry on a failed gateway call (transient blips must not kill a chain).
     * 2. When require_json is on, one repair round that feeds the parse error
     *    back to the model (structured-output best practice).
     */
    private function generateWithRetryAndValidation(AiTextService $ai, AiAgent $agent, string $systemPrompt, string $userMessage): AiTextResult
    {
        $metadata = ['agent_id' => $agent->id, 'agent_name' => $agent->name];

        $result = $ai->generate(systemPrompt: $systemPrompt, userMessage: $userMessage, model: $agent->model, metadata: $metadata);

        if (! $result->success) {
            sleep(3); // ponytail: fixed backoff, exponential if attempts grow
            $result = $ai->generate(systemPrompt: $systemPrompt, userMessage: $userMessage, model: $agent->model, metadata: $metadata);
        }

        if ($result->success && $agent->require_json && json_decode((string) $result->text) === null) {
            $repairMessage = $userMessage."\n\n## Your previous response was not valid JSON (parse error: ".json_last_error_msg().'). Respond again with valid JSON only.';
            $result = $ai->generate(systemPrompt: $systemPrompt, userMessage: $repairMessage, model: $agent->model, metadata: $metadata);

            if ($result->success && json_decode((string) $result->text) === null) {
                $result = AiTextResult::failure('Output failed JSON validation after retry.', $result->model, $result->responseTimeMs);
            }
        }

        return $result;
    }

    /**
     * Context assembly shared by the php and go engines (static skips it —
     * the text IS the message). Returns [userMessage, systemPrompt].
     *
     * @return array{0: string, 1: string}
     */
    private function assemblePrompts(AiAgent $agent, AiAgentRun $run, ChatContextService $context, AiAgentMemoryService $memory): array
    {
        $userMessage = 'Current date/time: '.now()->format('l, d F Y H:i T').'. Perform your task now.';

        if ($agent->include_context) {
            $userMessage .= "\n\n## Dashboard context (current year)\n".$context->getQuickStats();
        }

        if ($agent->include_memory) {
            $recent = $memory->recentForPrompt((int) config('ai.agents.memory_inject_limit', 25));

            if ($recent !== '') {
                $userMessage .= "\n\n## Shared agent memory (most recent first)\n".$recent;
            }
        }

        // Attached reference files — extracted at upload, just budgeted text here.
        $filesBlock = $this->buildAttachedFilesBlock($agent, (int) config('ai.agents.file_inject_limit', 8000));
        if ($filesBlock !== '') {
            $userMessage .= "\n\n".$filesBlock;
        }

        // Investigation documents (best-effort — a failing extraction skips the doc).
        if ($agent->include_documents) {
            $docs = $this->buildInvestigationDocsBlock((int) config('ai.agents.document_inject_count', 5), (int) config('ai.agents.document_inject_limit', 6000));
            if ($docs !== '') {
                $userMessage .= "\n\n".$docs;
            }
        }

        // Long-term incident memory: the corpus catalog. Newest first —
        // when the cap bites, truncation eats the OLDEST history, never
        // the incidents an analyst most needs. Absent until the hourly
        // builder's first run.
        if ($agent->include_corpus) {
            $catalog = app(\App\Services\Markdown\IncidentMarkdownCorpusService::class)->catalog();
            if ($catalog !== null && $catalog !== '') {
                $newestFirst = collect(explode("\n", $catalog))->filter()->reverse()->implode("\n");
                $userMessage .= "\n\n## Incident catalog (newest first, one line each)\n"
                    .Str::limit($newestFirst, (int) config('ai.agents.corpus_inject_limit', 6000));
            }
        }

        // Chain handoff: the dependent sees what its upstream actually
        // produced, not just the ≤3 memory bullets. NullOnDelete means a
        // pruned upstream simply skips this block.
        $triggering = $run->triggeredBy;
        if ($triggering && ($triggering->output ?? '') !== '') {
            $userMessage .= "\n\n## Upstream result from \"{$triggering->agent?->name}\" (completed ".$triggering->completed_at?->format('H:i').")\n"
                .Str::limit((string) $triggering->output, (int) config('ai.agents.upstream_inject_limit', 4000));
        }

        // The org block is teamwork context, not memory — shown whenever a
        // hierarchy exists, so agents always know the team structure.
        if ($tree = $memory->orgTreeForPrompt($agent)) {
            $userMessage .= "\n\n## Agent organization\n".$tree;
        }

        // $run->input stays the pure instructions snapshot; the memory
        // protocol and output contract are runtime suffixes only.
        $systemPrompt = (string) $run->input;

        if ($agent->include_memory) {
            $systemPrompt .= "\n\n".AiAgentMemoryService::PROTOCOL;
        }

        if (($agent->expected_output ?? '') !== '') {
            $systemPrompt .= "\n\n## Expected output\n".trim((string) $agent->expected_output);
        }

        if ($agent->require_json) {
            $systemPrompt .= "\n\nRespond with a single valid JSON value only — no markdown fences, no prose.";
        }

        return [$userMessage, $systemPrompt];
    }

    /**
     * Go engine with the same JSON-repair contract as the php path: one
     * repair re-POST feeding the parse error back, then give up. Transport
     * failures are NOT retried here — the next cron tick is the retry.
     */
    private function runGoWithValidation(AiAgent $agent, AiAgentRun $run, string $systemPrompt, string $userMessage): AiTextResult
    {
        $result = $this->runViaGoEngine($agent, $run, $systemPrompt, $userMessage, 'task');

        if ($result->success && $agent->require_json && json_decode((string) $result->text) === null) {
            $repairMessage = $userMessage."\n\n## Your previous response was not valid JSON (parse error: ".json_last_error_msg().'). Respond again with valid JSON only.';
            $result = $this->runViaGoEngine($agent, $run, $systemPrompt, $repairMessage, 'task');

            if ($result->success && json_decode((string) $result->text) === null) {
                $result = AiTextResult::failure('Output failed JSON validation after retry.', $result->model, $result->responseTimeMs);
            }
        }

        return $result;
    }

    /**
     * One call to the Go engine's internal endpoint (task = LLM tool loop,
     * message = deliver text as-is). ForceFills DingTalk delivery facts onto
     * the run (persisted by the caller's save); a failed delivery never
     * fails the run itself.
     */
    private function runViaGoEngine(AiAgent $agent, AiAgentRun $run, ?string $system, string $userMessage, string $mode): AiTextResult
    {
        $token = (string) config('ai.go_agent.token');

        if ($token === '') {
            return AiTextResult::failure('Go agent not configured — set GO_AGENT_INTERNAL_TOKEN.');
        }

        $payload = [
            'run_id' => $run->id,
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'mode' => $mode,
            'text' => Str::limit($userMessage, 60000),
        ];

        if ($system !== null && $system !== '') {
            $payload['system'] = Str::limit($system, 60000);
        }

        if ($agent->model) {
            $payload['model'] = $agent->model;
        }

        // Only chat-created agents carry the conversation binding.
        if ($agent->dingtalk_conversation_id) {
            $payload['conversation_type'] = $agent->dingtalk_conversation_type;
            $payload['conversation_id'] = $agent->dingtalk_conversation_id;
            $payload['staff_id'] = $agent->dingtalk_staff_id;
        }

        $startedAt = microtime(true);

        try {
            $response = Http::withHeaders(['X-Agent-Token' => $token])
                ->timeout((int) config('ai.go_agent.timeout', 420))
                ->post(rtrim((string) config('ai.go_agent.base_url'), '/').'/internal/run', $payload);
        } catch (\Throwable $e) {
            return AiTextResult::failure('Go agent unreachable: '.$e->getMessage());
        }

        if ($response->status() === 401) {
            return AiTextResult::failure('Go agent rejected the request — X-Agent-Token mismatch (401).');
        }

        if (! $response->successful()) {
            return AiTextResult::failure('Go agent HTTP '.$response->status().': '.Str::limit((string) $response->body(), 500));
        }

        $data = $response->json();

        if (! is_array($data) || ($data['ok'] ?? null) !== true) {
            return AiTextResult::failure('Go agent error: '.Str::limit((string) ($data['error'] ?? 'malformed response'), 500));
        }

        if ($agent->dingtalk_conversation_id) {
            $run->forceFill([
                'delivery_status' => ($data['delivered'] ?? false) === true ? 'sent' : 'failed',
                'delivery_error' => isset($data['delivery_error']) ? (string) $data['delivery_error'] : null,
            ]);
        }

        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return AiTextResult::success(
            (string) ($data['output'] ?? ''),
            isset($data['model']) && $data['model'] !== '' ? (string) $data['model'] : (string) ($agent->model ?? ''),
            isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
            isset($data['response_time_ms']) ? (float) $data['response_time_ms'] : (microtime(true) - $startedAt) * 1000.0,
            $run->id,
        );
    }
}
