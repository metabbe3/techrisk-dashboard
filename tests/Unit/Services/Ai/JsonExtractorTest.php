<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Concerns\JsonExtractor;
use Tests\TestCase;

class JsonExtractorTest extends TestCase
{
    public function test_extracts_plain_json_object(): void
    {
        $result = JsonExtractor::extract('{"matched": ["alpha"], "suggested": ["beta"]}');

        $this->assertSame(['matched' => ['alpha'], 'suggested' => ['beta']], $result);
    }

    public function test_extracts_from_json_code_fence(): void
    {
        $content = "Here you go:\n```json\n{\"ranked_ids\": [3, 1, 2]}\n```\nLet me know!";

        $this->assertSame(['ranked_ids' => [3, 1, 2]], JsonExtractor::extract($content));
    }

    public function test_extracts_from_plain_code_fence(): void
    {
        $this->assertSame(['ok' => true], JsonExtractor::extract("```\n{\"ok\": true}\n```"));
    }

    public function test_strips_leading_think_block_before_json(): void
    {
        // Reasoning models (DeepSeek/Qwen) frequently prepend <think>…</think>.
        $content = '<think>I should rank by severity.</think>{"ranked_ids": [7, 4]}';

        $this->assertSame(['ranked_ids' => [7, 4]], JsonExtractor::extract($content));
    }

    public function test_strips_thinking_variant_tag(): void
    {
        $content = '<thinking>reasoning here</thinking>{"a": 1}';

        $this->assertSame(['a' => 1], JsonExtractor::extract($content));
    }

    public function test_ignores_trailing_prose_after_json(): void
    {
        $content = '{"matched": ["x"]} Hope this helps, let me know if you need more!';

        $this->assertSame(['matched' => ['x']], JsonExtractor::extract($content));
    }

    public function test_extracts_json_array(): void
    {
        // Feedback learning returns a bare JSON array of rules.
        $content = '["Prefer bullet points", "Cite incident numbers"]';

        $this->assertSame(['Prefer bullet points', 'Cite incident numbers'], JsonExtractor::extract($content));
    }

    public function test_returns_null_for_empty_content(): void
    {
        $this->assertNull(JsonExtractor::extract(''));
    }

    public function test_returns_null_when_no_json_present(): void
    {
        $this->assertNull(JsonExtractor::extract('The model refused or returned prose only.'));
    }

    public function test_returns_null_for_invalid_json(): void
    {
        $this->assertNull(JsonExtractor::extract('{not valid json,}'));
    }
}
