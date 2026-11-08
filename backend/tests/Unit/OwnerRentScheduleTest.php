<?php

namespace Tests\Unit;

use App\Services\Fleet\OwnerRentSchedule;
use PHPUnit\Framework\TestCase;

class OwnerRentScheduleTest extends TestCase
{
    private OwnerRentSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedule = new OwnerRentSchedule;
    }

    public function test_stops_at_agreement_end_and_skips_the_open_month(): void
    {
        $shares = $this->schedule->shares('2026-06-01', '2026-08-31', 2000, '2026-11-08');

        $this->assertSame([
            '2026-06' => 2000,
            '2026-07' => 2000,
            '2026-08' => 2000,
        ], $shares);
    }

    public function test_splits_a_cycle_across_calendar_months_and_holds_the_current_month(): void
    {
        $shares = $this->schedule->shares('2026-09-15', '2026-12-14', 3000, '2026-11-08');

        $this->assertSame(1600, $shares['2026-09']);
        $this->assertSame(1400 + 1645, $shares['2026-10']);
        $this->assertArrayNotHasKey('2026-11', $shares);
    }

    public function test_final_partial_cycle_is_prorated_after_it_ends(): void
    {
        $shares = $this->schedule->shares('2026-11-15', '2026-12-10', 3000, '2027-01-01');

        $this->assertSame(1600, $shares['2026-11']);
        $this->assertSame(1000, $shares['2026-12']);
    }
}
