<?php

namespace App\Services\Ai\Concerns;

/**
 * Stateful streaming filter that strips <think ...>...</think > and
 * <thinking>...</thinking> blocks from AI model output.
 *
 * Handles chunks split arbitrarily across streaming boundaries.
 */
class StripsThinkingTags
{
    private const MAX_TAG_LEN = 32;

    private bool $inside = false;

    private string $tagBuffer = '';

    private string $closeBuffer = '';

    public function filter(string $chunk): string
    {
        $output = '';
        $len = strlen($chunk);

        for ($i = 0; $i < $len; $i++) {
            $char = $chunk[$i];

            if ($this->inside) {
                $this->handleInside($char);
            } elseif ($this->tagBuffer !== '') {
                $output .= $this->handleOpenTagBuffer($char);
            } else {
                $output .= $this->handleOutside($char);
            }
        }

        return $output;
    }

    /**
     * Call after the stream ends to recover any buffered non-tag content.
     */
    public function flush(): string
    {
        $leftover = '';

        if (! $this->inside && $this->tagBuffer !== '') {
            // Buffer held a partial "<thi..." that never completed → it's real content
            $leftover = $this->tagBuffer;
        }

        // If still inside a thinking block at stream end, suppress everything

        $this->inside = false;
        $this->tagBuffer = '';
        $this->closeBuffer = '';

        return $leftover;
    }

    public function reset(): void
    {
        $this->inside = false;
        $this->tagBuffer = '';
        $this->closeBuffer = '';
    }

    private function handleOutside(string $char): string
    {
        if ($char === '<') {
            $this->tagBuffer = '<';

            return '';
        }

        return $char;
    }

    private function handleOpenTagBuffer(string $char): string
    {
        $this->tagBuffer .= $char;
        $buf = strtolower($this->tagBuffer);

        // Check if buffer matches an opening thinking tag
        if (preg_match('/^<think(ing)?[\s>]/i', $this->tagBuffer)) {
            $this->inside = true;
            $this->tagBuffer = '';
            $this->closeBuffer = '';

            return '';
        }

        // If buffer can no longer possibly match "<think..." → emit it all
        if (! str_starts_with('<thinking', $buf) && ! str_starts_with('<think', $buf)) {
            $emit = $this->tagBuffer;
            $this->tagBuffer = '';

            return $emit;
        }

        // Safety: if buffer grows beyond any plausible tag, emit it
        if (strlen($this->tagBuffer) > self::MAX_TAG_LEN) {
            $emit = $this->tagBuffer;
            $this->tagBuffer = '';

            return $emit;
        }

        return '';
    }

    private function handleInside(string $char): void
    {
        if ($char === '<') {
            $this->closeBuffer = '<';

            return;
        }

        if ($this->closeBuffer !== '') {
            $this->closeBuffer .= $char;

            // Check for closing tag match
            if (preg_match('/^<\/think(ing)?>/i', $this->closeBuffer)) {
                $this->inside = false;
                $this->closeBuffer = '';

                return;
            }

            // If it can't possibly match "</think..." or "</thinking>", reset buffer
            $lower = strtolower($this->closeBuffer);
            if (! str_starts_with('</thinking', $lower) && ! str_starts_with('</think', $lower)) {
                $this->closeBuffer = '';

                return;
            }

            // Safety: reset if buffer too long
            if (strlen($this->closeBuffer) > self::MAX_TAG_LEN) {
                $this->closeBuffer = '';
            }
        }
    }

    /**
     * Non-streaming helper: strip all thinking tags from a complete string.
     */
    public static function stripStatic(string $text): string
    {
        return preg_replace('/<think(?:ing)?[^>]*>.*?<\/think(?:ing)?>/si', '', $text);
    }
}
