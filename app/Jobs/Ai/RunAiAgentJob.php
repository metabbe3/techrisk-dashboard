<?php

declare(strict_types=1);
namespace App\Jobs\Ai;

use App\Enums\AiAgentRunStatus;
use App\Models\AiAgent;
use App\Models\AiAgentMemory;
use App\Models\AiAgentRun;
use App\Services\Ai\AiAgentMemoryService;
use App\Services\Ai\AiTextResult;
use App\Services\Ai\AiTextService;
use App\Services\Ai\ChatContextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

// ponytail: v1 agents are text-only — instructions are prompt text, never eval'd,
// no tool/function execution. Tool use = Phase 2 design (allow-list over the
// existing tool registry), not a config flag.
class RunAiAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

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

        // Lock TTL (600s) > job timeout (300s) so a killed worker self-heals.
        $lock = Cache::lock("ai-agent-run:{$this->agentId}", 600);

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

            // generate() resolves model null → AiSetting default_model, and writes
            // the ai_usage_logs row (field_type 'agent') itself.
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

            $result = $this->generateWithRetryAndValidation($ai, $agent, $systemPrompt, $userMessage);

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
}
