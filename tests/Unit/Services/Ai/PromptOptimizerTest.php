<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\PromptOptimizer;
use Tests\TestCase;

class PromptOptimizerTest extends TestCase
{
    private PromptOptimizer $optimizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->optimizer = new PromptOptimizer;
    }

    // --- normalizeWhitespace ---

    public function test_collapses_three_plus_blank_lines_to_two(): void
    {
        $input = "Line 1\n\n\n\nLine 2";
        $result = $this->optimizer->normalizeWhitespace($input);

        $this->assertEquals("Line 1\n\nLine 2", $result);
    }

    public function test_strips_trailing_whitespace_from_lines(): void
    {
        $input = "Line 1   \nLine 2\t\nLine 3";
        $result = $this->optimizer->normalizeWhitespace($input);

        $this->assertEquals("Line 1\nLine 2\nLine 3", $result);
    }

    public function test_removes_invisible_control_characters(): void
    {
        $input = "Hello\x00World\x07Test\x1FEnd";
        $result = $this->optimizer->normalizeWhitespace($input);

        $this->assertEquals('HelloWorldTestEnd', $result);
    }

    public function test_trims_leading_and_trailing_whitespace(): void
    {
        $input = "\n\n  Hello World  \n\n";
        $result = $this->optimizer->normalizeWhitespace($input);

        $this->assertEquals('Hello World', $result);
    }

    // --- stripEmptyFields ---

    public function test_strips_na_field_values(): void
    {
        $input = "## Incident\nRoot Cause: N/A\nTimeline: Some event happened\nTeam: N/A";
        $result = $this->optimizer->stripEmptyFields($input);

        $this->assertStringNotContainsString('Root Cause: N/A', $result);
        $this->assertStringNotContainsString('Team: N/A', $result);
        $this->assertStringContainsString('Timeline: Some event happened', $result);
    }

    public function test_strips_none_field_values(): void
    {
        $input = "Summary: None\nRoot Cause: Actual cause here";
        $result = $this->optimizer->stripEmptyFields($input);

        $this->assertStringNotContainsString('Summary: None', $result);
        $this->assertStringContainsString('Root Cause: Actual cause here', $result);
    }

    public function test_strips_dash_field_values(): void
    {
        $input = "Category: —\nNotes: Some actual notes";
        $result = $this->optimizer->stripEmptyFields($input);

        $this->assertStringNotContainsString('Category: —', $result);
        $this->assertStringContainsString('Notes: Some actual notes', $result);
    }

    public function test_preserves_fields_with_actual_content(): void
    {
        $input = "Root Cause: Database connection timeout\nSummary: System outage";
        $result = $this->optimizer->stripEmptyFields($input);

        $this->assertStringContainsString('Root Cause: Database connection timeout', $result);
        $this->assertStringContainsString('Summary: System outage', $result);
    }

    public function test_strips_na_in_list_items(): void
    {
        $input = "- Root Cause: N/A\n- Timeline: Event at 10:00";
        $result = $this->optimizer->stripEmptyFields($input);

        $this->assertStringNotContainsString('Root Cause: N/A', $result);
        $this->assertStringContainsString('Timeline: Event at 10:00', $result);
    }

    // --- removeFillerPhrases ---

    public function test_removes_please_note_that(): void
    {
        $input = 'Please note that the incident was caused by a timeout.';
        $result = $this->optimizer->removeFillerPhrases($input);

        $this->assertEquals('the incident was caused by a timeout.', $result);
    }

    public function test_removes_it_is_important_to_note(): void
    {
        $input = 'It is important to note that the system recovered.';
        $result = $this->optimizer->removeFillerPhrases($input);

        $this->assertEquals('the system recovered.', $result);
    }

    public function test_removes_needless_to_say(): void
    {
        $input = 'Needless to say, the root cause was identified.';
        $result = $this->optimizer->removeFillerPhrases($input);

        $this->assertEquals('the root cause was identified.', $result);
    }

    public function test_preserves_non_filler_content(): void
    {
        $input = 'The database connection pool was exhausted at 14:30.';
        $result = $this->optimizer->removeFillerPhrases($input);

        $this->assertEquals($input, $result);
    }

    // --- cleanMarkdownArtifacts ---

    public function test_strips_standalone_html_comments(): void
    {
        $input = "Line 1\n<!-- This is a comment -->\nLine 2";
        $result = $this->optimizer->cleanMarkdownArtifacts($input);

        $this->assertStringNotContainsString('<!-- This is a comment -->', $result);
        $this->assertStringContainsString('Line 1', $result);
        $this->assertStringContainsString('Line 2', $result);
    }

    public function test_preserves_inline_html_comments_in_instructions(): void
    {
        $input = 'Use this format: <!--FOLLOW_UP:["Q1?","Q2?","Q3?"]-->';
        $result = $this->optimizer->cleanMarkdownArtifacts($input);

        $this->assertStringContainsString('<!--FOLLOW_UP:["Q1?","Q2?","Q3?"]-->', $result);
    }

    public function test_collapses_multiple_horizontal_rules(): void
    {
        $input = "Section 1\n---\n---\n---\nSection 2";
        $result = $this->optimizer->cleanMarkdownArtifacts($input);

        // Should only have one horizontal rule
        $this->assertEquals(1, substr_count($result, '---'));
    }

    public function test_normalizes_heading_spacing(): void
    {
        $input = "##  Heading with extra space\n###   Another heading";
        $result = $this->optimizer->cleanMarkdownArtifacts($input);

        $this->assertStringContainsString('## Heading with extra space', $result);
        $this->assertStringContainsString('### Another heading', $result);
    }

    public function test_removes_empty_list_items(): void
    {
        $input = "Item 1\n-\nItem 2\n*\nItem 3";
        $result = $this->optimizer->cleanMarkdownArtifacts($input);

        $this->assertStringContainsString('Item 1', $result);
        $this->assertStringContainsString('Item 2', $result);
        $this->assertStringContainsString('Item 3', $result);
    }

    // --- combined optimize ---

    public function test_full_optimization_reduces_length(): void
    {
        $input = str_repeat(
            "## Incident Data\n\n"
            ."Root Cause: N/A\n"
            ."Timeline: N/A\n"
            ."Responsible Team: N/A\n"
            ."Please note that the system had a timeout issue.\n"
            ."It is important to note that monitoring was insufficient.\n"
            ."Summary: Database connection failed\n\n"
            ."<!-- Generated by system -->\n\n"
            ."\n\n\n",
            10
        );

        $result = $this->optimizer->optimize($input, 'chat');

        $this->assertLessThan(strlen($input), strlen($result));
        $this->assertStringContainsString('Database connection failed', $result);
        $this->assertStringNotContainsString('Root Cause: N/A', $result);
        $this->assertStringNotContainsString('Please note that', $result);
    }

    public function test_war_room_context_preserves_empty_fields(): void
    {
        $input = str_repeat(
            "## Incident Analysis\n\n"
            ."Root Cause: N/A\n"
            ."Timeline: Event at 14:30\n"
            ."Responsible Team: N/A\n"
            ."Please note that this is critical.\n\n",
            40
        );

        $result = $this->optimizer->optimize($input, 'war_room');

        // war_room context should preserve empty fields
        $this->assertStringContainsString('Root Cause: N/A', $result);
        $this->assertStringContainsString('Responsible Team: N/A', $result);
        // but still strip filler phrases
        $this->assertStringNotContainsString('Please note that', $result);
    }

    public function test_skips_short_prompts(): void
    {
        config(['ai.prompt_optimization.min_length' => 2000]);
        $shortPrompt = str_repeat('Hello World ', 10);

        $result = $this->optimizer->optimize($shortPrompt);

        $this->assertEquals($shortPrompt, $result);
    }

    // --- stats tracking ---

    public function test_stats_track_optimization_results(): void
    {
        $input = str_repeat(
            "Root Cause: N/A\nPlease note that this happened.\n\n\n\n",
            50
        );

        $this->optimizer->optimize($input, 'chat');
        $stats = $this->optimizer->getStats();

        $this->assertEquals(strlen($input), $stats['original_length']);
        $this->assertLessThan($stats['original_length'], $stats['optimized_length']);
        $this->assertGreaterThan(0, $stats['chars_saved']);
        $this->assertGreaterThan(0, $stats['reduction_percent']);
        $this->assertGreaterThan(0, $stats['estimated_tokens_saved']);
        $this->assertContains('normalize_whitespace', $stats['rules_applied']);
        $this->assertContains('strip_empty_fields', $stats['rules_applied']);
        $this->assertContains('remove_filler_phrases', $stats['rules_applied']);
    }

    public function test_estimate_tokens_saved(): void
    {
        $input = str_repeat("Root Cause: N/A\n\n\n", 150);
        $this->optimizer->optimize($input, 'chat');

        $tokensSaved = $this->optimizer->estimateTokensSaved();

        $this->assertGreaterThan(0, $tokensSaved);
    }

    // --- config methods ---

    public function test_is_enabled_returns_config_value(): void
    {
        config(['ai.prompt_optimization.enabled' => true]);
        $this->assertTrue(PromptOptimizer::isEnabled());

        config(['ai.prompt_optimization.enabled' => false]);
        $this->assertFalse(PromptOptimizer::isEnabled());
    }

    public function test_get_min_length_returns_config_value(): void
    {
        config(['ai.prompt_optimization.min_length' => 5000]);
        $this->assertEquals(5000, PromptOptimizer::getMinLength());

        config(['ai.prompt_optimization.min_length' => 1000]);
        $this->assertEquals(1000, PromptOptimizer::getMinLength());
    }

    // --- edge cases ---

    public function test_handles_empty_string(): void
    {
        $result = $this->optimizer->optimize('');

        $this->assertEquals('', $result);
    }

    public function test_handles_already_optimized_prompt(): void
    {
        $input = "## Heading\nContent here.\nMore content.";
        $result = $this->optimizer->optimize($input, 'chat');

        // Short prompt should be returned as-is (below min_length default of 2000)
        $this->assertEquals($input, $result);
    }

    public function test_preserves_semantic_structure(): void
    {
        $input = str_repeat(
            "## Root Cause Analysis\n\n"
            ."The primary cause was database timeout.\n\n"
            ."### Contributing Factors\n\n"
            ."1. Connection pool exhaustion\n"
            ."2. Slow query performance\n"
            ."3. Missing failover mechanism\n\n"
            ."## Recommendations\n\n"
            ."- Increase connection pool size\n"
            ."- Add query timeout limits\n"
            ."- Implement circuit breaker pattern\n\n",
            10
        );

        $result = $this->optimizer->optimize($input, 'war_room');

        $this->assertStringContainsString('## Root Cause Analysis', $result);
        $this->assertStringContainsString('database timeout', $result);
        $this->assertStringContainsString('Connection pool exhaustion', $result);
        $this->assertStringContainsString('## Recommendations', $result);
        $this->assertStringContainsString('circuit breaker', $result);
    }
}
