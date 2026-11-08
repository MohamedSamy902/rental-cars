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

    public function test_full_calendar_month_inside_a_mid_month_agreement_is_exact(): void
    {
        $shares = $this->schedule->shares('2026-09-15', '2026-12-14', 3000, '2026-11-08');

        $this->assertSame(1600, $shares['2026-09']);
        $this->assertSame(3000, $shares['2026-10']);
        $this->assertArrayNotHasKey('2026-11', $shares);
        $this->assertArrayNotHasKey('2026-12', $shares);
    }

    public function test_september_start_keeps_later_full_months_exact_after_they_end(): void
    {
        $shares = $this->schedule->shares('2026-09-15', '2026-12-14', 3000, '2027-01-15');

        $this->assertSame([
            '2026-09' => 1600,
            '2026-10' => 3000,
            '2026-11' => 3000,
            '2026-12' => intdiv(14 * 3000, 31),
        ], $shares);
    }

    public function test_april_full_month_and_three_civil_days_ignore_cairo_dst(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Africa/Cairo');
        try {
            $full = $this->schedule->shares('2026-04-01', '2026-04-30', 3000, '2026-11-08');
            $slice = $this->schedule->shares('2026-04-23', '2026-04-25', 3000, '2026-11-08');
            $october = $this->schedule->shares('2026-10-01', '2026-10-31', 3100, '2026-11-08');
            $edge = $this->schedule->shares('2026-10-30', '2026-10-31', 3100, '2026-11-08');
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame(['2026-04' => 3000], $full);
        $this->assertSame(['2026-04' => 300], $slice);
        $this->assertSame(['2026-10' => 3100], $october);
        $this->assertSame(['2026-10' => 200], $edge);
    }

    public function test_month_lengths_first_last_and_one_day_agreements(): void
    {
        $this->assertSame(
            ['2026-09' => 3000],
            $this->schedule->shares('2026-09-01', '2026-09-30', 3000, '2026-11-08'),
        );
        $this->assertSame(
            ['2026-02' => 3000],
            $this->schedule->shares('2026-02-01', '2026-02-28', 3000, '2026-11-08'),
        );
        $this->assertSame(
            ['2024-02' => 3000],
            $this->schedule->shares('2024-02-01', '2024-02-29', 3000, '2026-11-08'),
        );
        $this->assertSame(
            ['2024-02' => intdiv(3000, 29)],
            $this->schedule->shares('2024-02-29', '2024-02-29', 3000, '2026-11-08'),
        );
        $this->assertSame(
            ['2026-04' => intdiv(3000, 30)],
            $this->schedule->shares('2026-04-01', '2026-04-01', 3000, '2026-11-08'),
        );
        $this->assertSame(
            ['2026-04' => intdiv(3000, 30)],
            $this->schedule->shares('2026-04-30', '2026-04-30', 3000, '2026-11-08'),
        );
        $this->assertSame(
            ['2026-04' => intdiv(3000, 30)],
            $this->schedule->shares('2026-04-24', '2026-04-24', 3000, '2026-11-08'),
        );
    }

    public function test_final_partial_month_uses_that_months_length(): void
    {
        $shares = $this->schedule->shares('2026-11-15', '2026-12-10', 3000, '2027-01-01');

        $this->assertSame(1600, $shares['2026-11']);
        $this->assertSame(intdiv(10 * 3000, 31), $shares['2026-12']);
    }

    public function test_unfinished_current_month_is_not_booked(): void
    {
        $this->assertSame(
            [],
            $this->schedule->shares('2026-11-01', '2026-11-30', 3000, '2026-11-08'),
        );
        $this->assertSame(
            [],
            $this->schedule->shares('2026-11-01', '2026-11-30', 3000, '2026-11-30'),
        );
    }
}
