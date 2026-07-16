<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\Concerns\NormalizesUsage;
use Tests\TestCase;

class NormalizesUsageTest extends TestCase
{
    private function normalizer(): object
    {
        return new class
        {
            use NormalizesUsage;

            public function publicNormalize(?array $usage): array
            {
                return $this->normalizeUsage($usage);
            }
        };
    }

    public function test_openai_field_names_pass_through(): void
    {
        $result = $this->normalizer()->publicNormalize([
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
        ]);

        $this->assertSame(['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150], $result);
    }

    public function test_anthropic_field_names_are_mapped(): void
    {
        // Anthropic-routed gateways report input_tokens/output_tokens — without
        // normalization these read as null and break budgets/dashboards.
        $result = $this->normalizer()->publicNormalize([
            'input_tokens' => 120,
            'output_tokens' => 40,
        ]);

        $this->assertSame(120, $result['prompt_tokens']);
        $this->assertSame(40, $result['completion_tokens']);
        $this->assertSame(160, $result['total_tokens']); // computed when absent
    }

    public function test_total_is_computed_when_absent(): void
    {
        $result = $this->normalizer()->publicNormalize([
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
        ]);

        $this->assertSame(15, $result['total_tokens']);
    }

    public function test_explicit_total_is_preserved(): void
    {
        $result = $this->normalizer()->publicNormalize([
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 999,
        ]);

        $this->assertSame(999, $result['total_tokens']);
    }

    public function test_null_or_empty_usage_returns_all_null(): void
    {
        $expected = ['prompt_tokens' => null, 'completion_tokens' => null, 'total_tokens' => null];

        $this->assertSame($expected, $this->normalizer()->publicNormalize(null));
        $this->assertSame($expected, $this->normalizer()->publicNormalize([]));
    }

    public function test_openai_fields_take_precedence_over_anthropic_when_both_present(): void
    {
        $result = $this->normalizer()->publicNormalize([
            'prompt_tokens' => 100,
            'input_tokens' => 999, // should be ignored
            'completion_tokens' => 50,
        ]);

        $this->assertSame(100, $result['prompt_tokens']);
        $this->assertSame(50, $result['completion_tokens']);
    }
}
