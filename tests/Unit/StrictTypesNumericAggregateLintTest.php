<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * BUG-021 guard: MySQL returns DECIMAL aggregates (->avg()/->average()/->sum())
 * as STRINGS; under declare(strict_types=1) feeding one into round()/abs()/
 * number_format()/floor()/ceil() is a fatal TypeError. Two prod rounds of this
 * (fc95c37 missed sites f8160eb had half-fixed) — this lint makes a third
 * incomplete sweep impossible by failing whenever a strict-typed app file has a
 * statement that combines a typed numeric function with an aggregate call and
 * no (float)/(int) cast.
 *
 * Known limitations: only catches producer+consumer in the SAME statement.
 * Split flows ($x = ...->avg(); round($x)) are invisible here, and a single
 * cast anywhere in a multi-entry array literal masks uncast siblings — both
 * stay covered by the rule "cast at the producer" (the realistic recurrence,
 * a new copy-paste aggregate line, IS caught).
 */
class StrictTypesNumericAggregateLintTest extends TestCase
{
    private const NUMERIC_FN = '/(^|[^\w$>])(round|abs|number_format|floor|ceil)\s*\(/';

    private const AGGREGATE_FN = '/->(avg|average|sum)\s*\(/';

    private const CAST = '/\(\s*(float|int)\s*\)/';

    #[Test]
    public function no_uncast_aggregates_feed_typed_numeric_functions(): void
    {
        $offenders = [];

        foreach ($this->strictAppFiles() as $path) {
            foreach ($this->statements((string) file_get_contents($path)) as [$stmt, $line]) {
                if ($stmt === '') {
                    continue;
                }
                if (preg_match(self::NUMERIC_FN, $stmt)
                    && preg_match(self::AGGREGATE_FN, $stmt)
                    && ! preg_match(self::CAST, $stmt)) {
                    $offenders[] = substr($path, strlen(base_path()) + 1).":{$line}";
                }
            }
        }

        $this->assertSame([], $offenders,
            "Aggregate feeding a typed numeric fn without a (float)/(int) cast (BUG-021 class, fatals on MySQL):\n"
            .implode("\n", $offenders));
    }

    /**
     * @return \Generator<string> absolute paths of strict-typed classes in app/
     */
    private function strictAppFiles(): \Generator
    {
        $it = new RegexIterator(
            new RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)
            ),
            '/\.php$/'
        );
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            $source = (string) file_get_contents($file->getPathname());
            if (str_contains($source, 'declare(strict_types=1)')) {
                yield $file->getPathname();
            }
        }
    }

    /**
     * Token-based statement splitter — `;` inside string literals or comments
     * is never a terminator, so no string-content false positives.
     *
     * @return \Generator<array{0: string, 1: int}> [statement source, start line]
     */
    private function statements(string $source): \Generator
    {
        $stmt = '';
        $startLine = null;
        $lastLine = 1;

        foreach (\token_get_all($source) as $token) {
            if (is_array($token)) {
                [$text, $lastLine] = [$token[1], $token[2]];
            } else {
                $text = $token;
            }

            if ($text === ';') {
                yield [trim($stmt), $startLine ?? $lastLine];
                $stmt = '';
                $startLine = null;

                continue;
            }

            if ($startLine === null && trim($text) !== '') {
                $startLine = $lastLine;
            }
            $stmt .= $text;
        }
    }
}
