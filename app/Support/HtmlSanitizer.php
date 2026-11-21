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
        $html = preg_replace('#<\s*('.self::DROP_TAGS.')\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<\s*(?:'.self::DROP_TAGS.')\b[^>]*/?>#is', '', $html) ?? $html;

        // 2. Strip event-handler & dangerous attributes (on*, srcdoc, javascript: hrefs).
        $html = preg_replace('#\s(?:'.self::DROP_ATTRS.')\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;

        // W11 fix: scheme check runs on the QUOTED-OR-UNQUOTED value after
        // html_entity_decode — the old quoted-only regex missed
        // href=javascript:alert(1) and href="&#106;avascript:alert(1)".
        $html = preg_replace_callback(
            '#(href|src)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
            function (array $m): string {
                $value = trim($m[2], "\"'");
                $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);

                if (preg_match('#^\s*(javascript|vbscript|data:text/html)#i', $decoded)) {
                    return $m[1].'="#"';
                }

                return $m[0];
            },
            $html,
        ) ?? $html;

        return $html;
    }
}
