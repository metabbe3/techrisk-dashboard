<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Incident;
use Tests\TestCase;

/**
 * Pins the owner rule (2026-09-30): positive MTTR renders as plain minutes
 * (180, not "3h 0m"); negative stays "X day(s)" (fund loss), null "-".
 */
class IncidentMttrFormattedTest extends TestCase
{
    /**
     * @dataProvider mttrValues
     */
    public function test_mttr_formatted_renders_plain_minutes(string $mttr, string $expected): void
    {
        $incident = new Incident(['mttr' => $mttr]);

        $this->assertSame($expected, $incident->mttr_formatted);
    }

    public static function mttrValues(): array
    {
        return [
            'whole minutes' => ['180', '180'],
            'under an hour' => ['45', '45'],
            'fractional minutes' => ['90.5', '90.5'],
            'over a day stays minutes' => ['1500', '1500'],
            'fund loss days plural' => ['-2', '2 days'],
            'fund loss day singular' => ['-1', '1 day'],
        ];
    }

    public function test_null_mttr_renders_dash(): void
    {
        $this->assertSame('-', (new Incident)->mttr_formatted);
    }

    public function test_absurd_mttr_renders_na(): void
    {
        $this->assertSame('N/A', (new Incident(['mttr' => '60000000']))->mttr_formatted);
    }
}
