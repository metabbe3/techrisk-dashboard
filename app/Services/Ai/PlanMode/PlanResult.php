<?php

namespace App\Services\Ai\PlanMode;

class PlanResult
{
    /**
     * @param  string|null  $planText  Brief explanation of the plan
     * @param  array  $subtasks  Array of {id, description, persona_key, domain}
     * @param  string|null  $error  Error message if planning failed
     * @param  string|null  $model  Model used for planning
     * @param  string|null  $thinkingContent  Reasoning content from the model
     * @param  int|null  $totalTokens  Total tokens used
     * @param  bool  $needsClarification  Whether the question needs clarification
     * @param  array  $clarificationQuestions  Follow-up questions for the user
     * @param  bool  $deepResearchNeeded  Whether gap analysis found research needs
     * @param  array  $researchTopics  Topics that need additional research
     * @param  array|null  $gapAnalysis  Gap analysis result data
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $planText = null,
        public readonly array $subtasks = [],
        public readonly ?string $error = null,
        public readonly ?string $model = null,
        public readonly ?string $thinkingContent = null,
        public readonly ?int $totalTokens = null,
        public readonly bool $needsClarification = false,
        public readonly array $clarificationQuestions = [],
        public readonly bool $deepResearchNeeded = false,
        public readonly array $researchTopics = [],
        public readonly ?array $gapAnalysis = null,
    ) {}

    public static function success(string $planText, array $subtasks, string $model, ?int $totalTokens = null, ?string $thinkingContent = null): self
    {
        return new self(
            success: true,
            planText: $planText,
            subtasks: $subtasks,
            model: $model,
            totalTokens: $totalTokens,
            thinkingContent: $thinkingContent,
        );
    }

    public static function failure(string $error, ?string $model = null): self
    {
        return new self(
            success: false,
            error: $error,
            model: $model,
        );
    }

    public static function needsClarification(array $questions, string $model, ?int $totalTokens = null, ?string $thinkingContent = null): self
    {
        return new self(
            success: true,
            needsClarification: true,
            clarificationQuestions: $questions,
            model: $model,
            totalTokens: $totalTokens,
            thinkingContent: $thinkingContent,
        );
    }

    public static function needsResearch(array $gapAnalysis, array $researchTopics, string $planText, array $subtasks, string $model, ?int $totalTokens = null): self
    {
        return new self(
            success: true,
            deepResearchNeeded: true,
            gapAnalysis: $gapAnalysis,
            researchTopics: $researchTopics,
            planText: $planText,
            subtasks: $subtasks,
            model: $model,
            totalTokens: $totalTokens,
        );
    }
}
