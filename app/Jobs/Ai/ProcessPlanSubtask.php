<?php

namespace App\Jobs\Ai;

use App\Models\ChatPlanSubtask;
use App\Services\Ai\PlanMode\PlanModeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPlanSubtask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 300;

    public $backoff = 10;

    public function __construct(
        public ChatPlanSubtask $subtask,
        public string $userMessage,
        public array $referencedIds = [],
        public ?string $userModel = null,
    ) {
        $this->onQueue(config('ai.plan_mode.queue', 'war-room'));
    }

    public function handle(PlanModeService $planService): void
    {
        $fresh = $this->subtask->fresh();
        if (! $fresh || $fresh->isCompleted()) {
            return;
        }

        $planService->processSubtask($this->subtask, $this->userMessage, $this->referencedIds, $this->userModel);
    }

    public function failed(\Throwable $exception): void
    {
        $fresh = $this->subtask->fresh();
        if (! $fresh || $fresh->isCompleted()) {
            return;
        }

        Log::warning('Plan subtask job failed', [
            'plan_id' => $fresh->plan_id,
            'subtask_index' => $fresh->subtask_index,
            'error' => $exception->getMessage(),
        ]);

        $fresh->markFailed('Job failed: '.$exception->getMessage());

        app(PlanModeService::class)->onSubtaskCompleted(
            $fresh->plan_id,
            $fresh->conversation_id,
            $this->userMessage,
            $this->referencedIds,
            $this->userModel,
        );
    }
}
