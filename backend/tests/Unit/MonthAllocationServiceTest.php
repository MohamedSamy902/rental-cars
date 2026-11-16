<?php

namespace Tests\Unit;

use App\Services\Accounting\MonthAllocationService;
use App\Services\Contracts\ContractPricingService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class MonthAllocationServiceTest extends TestCase
{
    public function test_allocates_rent_across_two_months(): void
    {
        $svc = new MonthAllocationService(new ContractPricingService);
        $start = new DateTimeImmutable('2026-01-28 10:00:00');
        $end = new DateTimeImmutable('2026-02-02 10:00:00');

        $rows = $svc->allocateRentByMonth($start, $end, 5000);

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['month']);
        $this->assertSame(2, $rows[1]['month']);
        $this->assertSame(5000, $rows[0]['amount'] + $rows[1]['amount']);
    }

    public function test_jan30_feb3_at_100_per_day_is_exactly_200_200(): void
    {
        $svc = new MonthAllocationService(new ContractPricingService);
        $start = new DateTimeImmutable('2026-01-30 00:00:00');
        $end = new DateTimeImmutable('2026-02-03 00:00:00');

        $rows = $svc->allocateRentByMonth($start, $end, 400);

        $this->assertCount(2, $rows);
        $this->assertSame(200, $rows[0]['amount']);
        $this->assertSame(200, $rows[1]['amount']);
        $this->assertSame(400, $rows[0]['amount'] + $rows[1]['amount']);
    }

    public function test_nov_to_dec_day_shares_sum_to_period_days(): void
    {
        $pricing = new ContractPricingService;
        $svc = new MonthAllocationService($pricing);
        $start = new DateTimeImmutable('2026-11-16 12:00:00');
        $end = new DateTimeImmutable('2026-12-16 12:00:00');
        $totalDays = $pricing->countRentalDays($start, $end);
        $periodRent = $totalDays * 1000;

        $rows = $svc->allocateRentByMonth($start, $end, $periodRent);

        $this->assertCount(2, $rows);
        $this->assertSame(11, $rows[0]['month']);
        $this->assertSame(12, $rows[1]['month']);
        $this->assertSame($totalDays, $rows[0]['days'] + $rows[1]['days']);
        $this->assertSame($periodRent, $rows[0]['amount'] + $rows[1]['amount']);
        $this->assertLessThan($periodRent, $rows[0]['amount']);
        $this->assertGreaterThan(0, $rows[1]['amount']);
    }
}
