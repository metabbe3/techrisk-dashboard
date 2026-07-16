<?php

namespace App\Services\Ai\Concerns;

/**
 * Single source of truth for pulling a JSON payload out of an LLM response.
 *
 * Models vary wildly in how they wrap structured output: frontier models often
 * return a bare object, while cheaper/alternate models frequently prepend a
 * `<think>…</think>` reasoning block, wrap the payload in a ```json fence, or
 * trail conversational prose. Routing every structured call through this helper
 * makes those provider quirks invisible and stops features from silently
 * returning an empty default when the model's formatting drifts.
 */
final class JsonExtractor
{
    /**
     * Extract the first valid JSON object/array from an LLM response body.
     *
     * Strategy (first match wins):
     *  1. Strip `<think>`/`<thinking>` reasoning blocks.
     *  2. A fenced ```json / ``` block.
     *  3. The outermost {...} or [...] span.
     *  4. The whole (trimmed) body.
     *
     * @return array<int|string,mixed>|null
     */
    public static function extract(string $content): ?array
    {
        if ($content === '') {
            return null;
        }

        // 1. Drop reasoning tags (e.g. DeepSeek/Qwen) that precede the payload.
        $content = StripsThinkingTags::stripStatic($content);

        // 2. Fenced ```json ... ``` (or plain ```), tolerant of a language tag.
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $content, $m)) {
            $decoded = json_decode(trim($m[1]), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 3. Outermost {...} or [...] span.
        if (preg_match('/(\{[\s\S]*\}|\[[\s\S]*\])/', $content, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 4. Last resort: treat the whole body as JSON.
        $decoded = json_decode(trim($content), true);

        return is_array($decoded) ? $decoded : null;
    }
}
