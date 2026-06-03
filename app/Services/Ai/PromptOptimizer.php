<?php

declare(strict_types=1);

namespace App\Services\Ai;

class PromptOptimizer
{
    private array $stats = [
        'original_length' => 0,
        'optimized_length' => 0,
        'rules_applied' => [],
    ];

    /**
     * Optimize a prompt for reduced token usage while preserving semantic meaning.
     *
     * @param  string  $prompt  The prompt text to optimize.
     * @param  string  $context  Optimization context: 'war_room' preserves empty fields
     *                           (agents need to know what's missing), 'chat' strips them.
     * @return string The optimized prompt.
     */
    public function optimize(string $prompt, string $context = 'general'): string
    {
        if (strlen($prompt) < static::getMinLength()) {
            return $prompt;
        }

        $this->stats = [
            'original_length' => strlen($prompt),
            'optimized_length' => 0,
            'rules_applied' => [],
        ];

        $result = $this->normalizeWhitespace($prompt);
        $result = $this->removeFillerPhrases($result);
        $result = $this->cleanMarkdownArtifacts($result);

        if ($context !== 'war_room') {
            $result = $this->stripEmptyFields($result);
        }

        $this->stats['optimized_length'] = strlen($result);

        return $result;
    }

    /**
     * Get optimization statistics from the last run.
     */
    public function getStats(): array
    {
        $original = $this->stats['original_length'];
        $optimized = $this->stats['optimized_length'];
        $saved = max(0, $original - $optimized);
        $percent = $original > 0 ? round(($saved / $original) * 100, 1) : 0;

        return [
            'original_length' => $original,
            'optimized_length' => $optimized,
            'chars_saved' => $saved,
            'reduction_percent' => $percent,
            'estimated_tokens_saved' => (int) ceil($saved / 4.0),
            'rules_applied' => $this->stats['rules_applied'],
        ];
    }

    /**
     * Estimate tokens saved by the last optimization.
     */
    public function estimateTokensSaved(): int
    {
        return (int) ceil(max(0, $this->stats['original_length'] - $this->stats['optimized_length']) / 4.0);
    }

    /**
     * Collapse consecutive blank lines, strip trailing whitespace, remove invisible chars.
     */
    public function normalizeWhitespace(string $text): string
    {
        $original = strlen($text);

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]+$/m', '', $text);
        $text = trim($text);

        if (strlen($text) < $original) {
            $this->stats['rules_applied'][] = 'normalize_whitespace';
        }

        return $text;
    }

    /**
     * Remove lines with empty/null field values like "Root Cause: N/A".
     */
    public function stripEmptyFields(string $text): string
    {
        $original = strlen($text);

        $text = preg_replace(
            '/^[ \t]*(?:[-*]\s+)?[\w\s()\/]+:\s*(?:N\/A|None|n\/a|none|—|–|\[\])\s*$/m',
            '',
            $text
        );

        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        if (strlen($text) < $original) {
            $this->stats['rules_applied'][] = 'strip_empty_fields';
        }

        return $text;
    }

    /**
     * Remove verbose filler phrases that add no semantic value.
     */
    public function removeFillerPhrases(string $text): string
    {
        $original = strlen($text);

        $fillerPhrases = [
            '/\bPlease note that\s+/i',
            '/\bIt is important to (?:note|remember|understand|mention) that\s+/i',
            '/\bPlease (?:be |keep in |)(?:aware|mind|advised|noted) that\s+/i',
            '/\bIt should be noted that\s+/i',
            '/\bIt is worth (?:noting|mentioning) that\s+/i',
            '/\bNeedless to say,\s*/i',
            '/\bIt goes without saying that\s+/i',
        ];

        foreach ($fillerPhrases as $pattern) {
            $text = preg_replace($pattern, '', $text);
        }

        if (strlen($text) < $original) {
            $this->stats['rules_applied'][] = 'remove_filler_phrases';
        }

        return $text;
    }

    /**
     * Strip HTML comments, excessive horizontal rules, and other markdown noise.
     */
    public function cleanMarkdownArtifacts(string $text): string
    {
        $original = strlen($text);

        // Only strip HTML comments that occupy their own entire line
        // (preserves inline examples like <!--FOLLOW_UP:...--> in instruction text)
        $text = preg_replace('/^[ \t]*<!--.*?-->[ \t]*$/m', '', $text);

        // Collapse multiple consecutive horizontal rules
        $text = preg_replace('/(?:^---+[ \t]*$\n*){2,}/m', "---\n", $text);

        // Normalize heading spacing (remove extra spaces after # markers)
        $text = preg_replace('/^(#{1,6})\s{2,}/m', '$1 ', $text);

        // Remove empty markdown list items
        $text = preg_replace('/^[ \t]*[-*]\s*$/m', '', $text);

        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        if (strlen($text) < $original) {
            $this->stats['rules_applied'][] = 'clean_markdown_artifacts';
        }

        return $text;
    }

    public static function isEnabled(): bool
    {
        return (bool) config('ai.prompt_optimization.enabled', false);
    }

    public static function getMinLength(): int
    {
        return (int) config('ai.prompt_optimization.min_length', 2000);
    }
}
