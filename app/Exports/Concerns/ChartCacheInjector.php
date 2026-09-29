<?php

declare(strict_types=1);

namespace App\Exports\Concerns;

/**
 * Post-processes a written XLSX so native charts carry value caches
 * (numCache/strCache <c:pt> entries). PhpSpreadsheet serializes chart
 * XML during save — by the time any Excel event hook runs it is too
 * late to mutate chart objects, so this operates on the finished zip.
 *
 * Without caches Excel renders fine (recalc on open) but Numbers,
 * QuickLook and Google Sheets show empty charts (BUG-013).
 *
 * Usage: $path = ChartCacheInjector::inject($pathToXlsx);
 */
final class ChartCacheInjector
{
    /**
     * @return string path of the (possibly rewritten) file
     */
    public static function inject(string $path): string
    {
        $zip = new \ZipArchive;
        $res = $zip->open($path, \ZipArchive::CREATE);
        if ($res !== true) {
            return $path; // fail-open: uncached charts still open in Excel
        }

        // 1. Load every sheet's cell values (shared strings resolved).
        $sheets = self::sheetValues($zip);

        // 2. Rewrite each chart XML with cache points filled.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === null || ! preg_match('#^xl/charts/chart\d+\.xml$#', $name)) {
                continue;
            }
            $xml = $zip->getFromIndex($i);
            if ($xml === false) {
                continue;
            }
            $new = self::fillChartXml($xml, $sheets);
            if ($new !== $xml) {
                $zip->addFromString($name, $new);
            }
        }

        $zip->close();

        return $path;
    }

    /**
     * @return array<string, array<string, string>> sheetName => [addr => value]
     */
    private static function sheetValues(\ZipArchive $zip): array
    {
        $strings = [];
        $sst = $zip->getFromName('xl/sharedStrings.xml');
        if ($sst !== false && preg_match_all('#<si>(?:<t[^>]*>)?([^<]*)(?:</t>)?</si>#', $sst, $m)) {
            $strings = $m[1];
        }

        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $nameToTarget = [];
        if ($wb !== false && $rels !== false && preg_match_all('#<sheet[^>]*name="([^"]+)"[^>]*r:id="(rId\d+)"#', $wb, $m)) {
            $ids = array_combine($m[2], $m[1]);
            if (preg_match_all('#<Relationship[^>]*Id="(rId\d+)"[^>]*Target="([^"]+)"#', $rels, $r)) {
                foreach ($r[1] as $k => $rid) {
                    $target = str_replace('worksheets/', 'xl/worksheets/', ltrim($r[2][$k], '/'));
                    $target = preg_replace('#^(?!xl/)#', 'xl/', $target);
                    $nameToTarget[$ids[$rid] ?? ''] = $target;
                }
            }
        }

        $out = [];
        foreach ($nameToTarget as $sheetName => $target) {
            $xml = $zip->getFromName($target);
            if ($xml === false) {
                continue;
            }
            $cells = [];
            if (preg_match_all('#<c r="([A-Z]+\d+)"([^>]*)>(?:<v>([^<]*)</v>)?#', $xml, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $c) {
                    $val = $c[3] ?? null;
                    if ($val === null) {
                        continue;
                    }
                    if (str_contains($c[2], 't="s"')) {
                        $idx = (int) $val;
                        $val = $strings[$idx] ?? (string) $idx;
                    }
                    $cells[$c[1]] = $val;
                }
            }
            $out[$sheetName] = $cells;
        }

        return $out;
    }

    private static function fillChartXml(string $xml, array $sheets): string
    {
        return preg_replace_callback(
            '#<(c:strRef|c:numRef)><c:f>([^<]+)</c:f>(?:<(c:strCache|c:numCache)><c:ptCount val="(\d+)"/></c:\3>)?#',
            function ($m) use ($sheets) {
                [$all, $refKind, $ref, $cacheTag, $ptCount] = [$m[0], $m[1], $m[2], $m[3] ?? null, $m[4] ?? null];
                $isStr = $refKind === 'c:strRef';
                $cacheTag ??= $isStr ? 'c:strCache' : 'c:numCache';

                $vals = self::resolveRef($ref, $sheets, $isStr);
                if ($vals === null) {
                    return $all;
                }

                $pts = '';
                $n = 0;
                foreach (array_values($vals) as $idx => $v) {
                    if ($v === null) {
                        continue; // gaps stay uncached — viewers interpolate
                    }
                    $pts .= '<c:pt idx="'.$idx.'"><c:v>'.htmlspecialchars($v, ENT_XML1).'</c:v></c:pt>';
                    $n = $idx + 1;
                }
                if ($n === 0) {
                    return $all;
                }

                return '<'.$refKind.'><c:f>'.$ref.'</c:f><'.$cacheTag.'><c:ptCount val="'.$n.'"/>'.$pts.'</'.$cacheTag.'>';
            },
            $xml
        ) ?? $xml;
    }

    /**
     * @return array<int, ?string>|null
     */
    private static function resolveRef(string $ref, array $sheets, bool $isStr): ?array
    {
        if (! str_contains($ref, '!')) {
            return null;
        }
        [$sheetName, $range] = explode('!', $ref, 2);
        $sheetName = trim($sheetName, "'\"");
        if (! isset($sheets[$sheetName])) {
            return null;
        }
        $cells = $sheets[$sheetName];
        if (! preg_match('#^\$?([A-Z]+)\$?(\d+):\$?([A-Z]+)\$?(\d+)$#', $range, $m)) {
            return null;
        }
        [, $c1, $r1, $c2, $r2] = $m;
        if ($c1 !== $c2 && (int) $r1 !== (int) $r2) {
            return null; // 2D unsupported
        }

        $vals = [];
        if ($c1 === $c2) {
            for ($r = (int) $r1; $r <= (int) $r2; $r++) {
                $vals[] = $cells[$c1.$r] ?? null;
            }
        } else {
            $from = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($c1);
            $to = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($c2);
            for ($ci = $from; $ci <= $to; $ci++) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci);
                $vals[] = $cells[$col.$r1] ?? null;
            }
        }

        return $vals;
    }
}
