<?php

declare(strict_types=1);

namespace App\Services\Ai;

class TokenEstimator
{
    /**
     * Estimate token count with JSON-density awareness.
     *
     * JSON-heavy content (syntax chars like {}, ", :, []) uses ~3.2 chars/token.
     * Natural language text uses ~4.0 chars/token.
     * Multibyte characters (Indonesian, CJK) are accounted for via mb_strlen.
     */
    public static function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        $length = mb_strlen($text);

        $jsonChars = substr_count($text, '{') + substr_count($text, '}')
            + substr_count($text, '"') + substr_count($text, ':')
            + substr_count($text, ',') + substr_count($text, '[')
            + substr_count($text, ']');

        $jsonDensity = $jsonChars / $length;

        $charsPerToken = 4.0 - ($jsonDensity * 0.8);
        $charsPerToken = max(3.0, min(4.0, $charsPerToken));

        return (int) ceil($length / $charsPerToken);
    }
}
