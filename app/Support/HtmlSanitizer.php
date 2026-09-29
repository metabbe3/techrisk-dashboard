<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal HTML sanitizer for PDF/blade views that must render rich HTML
 * (Parsedown output, AI-generated reports) without exposing script handlers.
 *
 * Standard B1: any {!! !!} output MUST pass through HtmlSanitizer::clean()
 * unless the string is provably server-generated from a whitelist.
 */
final class HtmlSanitizer
{
    /** Tags removed entirely (with content). */
    private const DROP_TAGS = 'script|style|iframe|object|embed|link|meta|form|svg|math';

    /** Attributes removed wherever they appear. */
    private const DROP_ATTRS = 'on[a-z]+|srcdoc|formaction|xlink:href|data-.*';

    public static function clean(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // 1. Drop dangerous blocks with their content.
        $html = preg_replace('#<\s*(' . self::DROP_TAGS . ')\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<\s*(?:' . self::DROP_TAGS . ')\b[^>]*/?>#is', '', $html) ?? $html;

        // 2. Strip event-handler & dangerous attributes (on*, srcdoc, javascript: hrefs).
        $html = preg_replace('#\s(?:' . self::DROP_ATTRS . ')\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
        $html = preg_replace('#(href|src)\s*=\s*(["\'])\s*(?:javascript|vbscript|data:text/html)[^"\']*\2#i', '$1="#"', $html) ?? $html;

        return $html;
    }
}
