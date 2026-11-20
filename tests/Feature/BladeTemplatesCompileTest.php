<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;
use Throwable;

/**
 * BUG-2026-11-18: the kanban board shipped a Blade landmine — the
 * expression form @php(...) mixed with block-form @php...@endphp. Laravel's
 * storePhpBlocks() regex (/(?<!@)@php(.*?)@endphp/s) treats @php( as a block
 * opener, swallows the next @endphp, and emits its captured range verbatim —
 * an unclosed <?php( that turns every following directive into raw text and
 * parse-errors the page (500 on /admin/incidents/board).
 *
 * The board cannot render under SQLite (FIELD() ordering — see
 * UserFacingCrashersTest), so this file's failure mode is invisible to
 * component tests. A compile+lint sweep over every template catches the whole
 * class instead, DB-free.
 */
class BladeTemplatesCompileTest extends TestCase
{
    public function test_every_blade_template_compiles_to_valid_php(): void
    {
        $viewsPath = realpath(resource_path('views'));
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($viewsPath, \FilesystemIterator::SKIP_DOTS)
        );

        $checked = 0;
        $failures = [];

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! str_ends_with($path, '.blade.php')) {
                continue;
            }

            $checked++;

            try {
                $compiled = Blade::compileString(file_get_contents($path));
            } catch (Throwable $e) {
                $failures[] = str_replace($viewsPath.'/', '', $path).' — compile: '.$e->getMessage();

                continue;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'blade_lint_');
            file_put_contents($tmp, $compiled);
            exec('php -l '.escapeshellarg($tmp).' 2>&1', $output, $exit);
            unlink($tmp);

            if ($exit !== 0) {
                $failures[] = str_replace($viewsPath.'/', '', $path).' — '.implode(' ', $output);
            }
            $output = [];
        }

        // Floor tracks the template count (47 after the 2026-11-20 command-
        // palette deletion removed the sidebar search-trigger blade); a
        // sudden drop means templates vanished unexpectedly.
        $this->assertGreaterThan(46, $checked, 'Blade sweep checked suspiciously few templates.');
        $this->assertSame([], $failures, "Templates that do not compile to valid PHP:\n".implode("\n", $failures));
    }
}
