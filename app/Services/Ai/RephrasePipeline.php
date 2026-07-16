<?php

namespace App\Services\Ai;

use App\Services\Ai\Concerns\InteractsWithAiApi;
use App\Services\Ai\Concerns\NormalizesUsage;
use App\Services\WarRoom\WarRoomStreamingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Two-stage rephrase/render for long outputs:
 *   1) a frontier (reasoning) model produces a concise structured skeleton;
 *   2) a fast (cheap) model renders that skeleton into clear, faithful prose.
 *
 * Stage 1 is not streamed (internal scaffolding). Stage 2 streams its deltas
 * back through $onRenderDelta so the host UI shows the human-readable result.
 */
class RephrasePipeline
{
    use InteractsWithAiApi;
    use NormalizesUsage;

    public function __construct(
        private readonly ModelRouter $router,
        private readonly WarRoomStreamingService $streaming,
    ) {}

    /**
     * @param  callable(string $delta, int $contentLength)|null  $onRenderDelta
     * @return array{content:string, models:array{skeleton:string,render:string}, usage:array, error:?string}
     */
    public function render(string $systemPrompt, string $userPrompt, ?callable $onRenderDelta = null): array
    {
        $skeletonModel = $this->router->pick('reasoning');
        $renderModel = $this->router->pick('fast');

        // Stage 1 — skeleton (non-streamed).
        $skeleton = $this->skeleton($skeletonModel, $systemPrompt, $userPrompt);
        if (trim($skeleton) === '') {
            Log::warning('[RephrasePipeline] Empty skeleton; rendering the raw prompt instead');
            $skeleton = $userPrompt;
        }

        // Stage 2 — render the skeleton into prose (streamed).
        $renderSystem = 'You turn a structured outline into clear, well-written, human-readable prose. '
            .'Expand each point into fluent sentences or short paragraphs. Use ONLY the facts in the '
            .'outline — do not invent new information. Keep it easy to understand.';
        $renderMessages = [
            ['role' => 'system', 'content' => $renderSystem],
            ['role' => 'user', 'content' => "Render this outline into clear prose:\n\n{$skeleton}"],
        ];

        $result = $this->streaming->streamCompletion(
            model: $renderModel,
            messages: $renderMessages,
            maxTokens: (int) config('ai.rephrase.render_max_tokens', 4096),
            onDelta: $onRenderDelta,
        );

        return [
            'content' => $result['content'] ?? '',
            'models' => ['skeleton' => $skeletonModel, 'render' => $renderModel],
            'usage' => $this->normalizeUsage($result['usage'] ?? null),
            'error' => $result['error'] ?? null,
        ];
    }

    private function skeleton(string $model, string $systemPrompt, string $userPrompt): string
    {
        $skeletonSystem = $systemPrompt."\n\n"
            .'First, produce a concise STRUCTURED OUTLINE (short bullet points) of the answer you would give. '
            .'Do not write the full answer — only the skeleton of key points, facts, and their order. Be complete but terse.';

        try {
            $response = Http::withHeaders($this->buildHeaders())
                ->timeout($this->getTimeout())
                ->post($this->buildUrl(), [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $skeletonSystem],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'max_tokens' => (int) config('ai.rephrase.skeleton_max_tokens', 1024),
                ]);

            return (string) ($response->json('choices.0.message.content') ?? '');
        } catch (\Throwable $e) {
            Log::warning('[RephrasePipeline] Skeleton call failed', ['model' => $model, 'error' => $e->getMessage()]);

            return '';
        }
    }
}
