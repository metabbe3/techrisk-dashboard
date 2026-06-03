<?php

namespace App\Jobs\Ai;

use App\Services\Ai\PlanMode\PlanModeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzePlanGaps implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $timeout = 60;

    public function __construct(
        public string $planId,
        public string $conversationId,
        public string $userMessage,
        public array $referencedIds = [],
        public ?string $userModel = null,
    ) {
        $this->onQueue(config('ai.plan_mode.queue', 'war-room'));
    }

    public function handle(PlanModeService $planService): void
    {
        $result = $planService->analyzeGaps(
            $this->planId,
            $this->conversationId,
            $this->userMessage,
            $this->referencedIds,
            $this->userModel,
        );

        if ($result->deepResearchNeeded) {
            $planService->runDeepResearch(
                $this->planId,
                $this->conversationId,
                $this->userMessage,
                $result->researchTopics,
                $this->referencedIds,
                $this->userModel,
            );
        } else {
            SynthesizePlanResults::dispatch(
                $this->planId,
                $this->conversationId,
                $this->userMessage,
                $this->referencedIds,
                $this->userModel,
            );
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('Plan gap analysis job failed', [
            'plan_id' => $this->planId,
            'error' => $exception->getMessage(),
        ]);

        SynthesizePlanResults::dispatch(
            $this->planId,
            $this->conversationId,
            $this->userMessage,
            $this->referencedIds,
            $this->userModel,
        );
    }
}
