<?php

namespace Tests\Unit;

use App\Services\Contracts\ContractPricingService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ContractPricingServiceTest extends TestCase
{
    private ContractPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = new ContractPricingService;
    }

    public function test_day_count_example_from_spec(): void
    {
        $start = new DateTimeImmutable('2026-10-01 10:00:00');
        $end = new DateTimeImmutable('2026-10-02 11:00:00');

        $this->assertSame(2, $this->pricing->countRentalDays($start, $end));
    }

    public function test_exact_24h_is_one_day(): void
    {
        $start = new DateTimeImmutable('2026-10-01 10:00:00');
        $end = new DateTimeImmutable('2026-10-02 10:00:00');

        $this->assertSame(1, $this->pricing->countRentalDays($start, $end));
    }

    public function test_allowed_kilometers(): void
    {
        $this->assertSame(600, $this->pricing->allowedKilometers(200, 3));
    }

    public function test_final_total_excludes_deposit(): void
    {
        $total = $this->pricing->finalTotal([
            'period_rent' => 3000,
            'discount' => 200,
            'excess_km_fee' => 150,
            'damage_fee' => 100,
            'operating_insurance_fee' => 50,
            'other_fees' => 25,
        ]);

        $this->assertSame(3125.0, $total);
    }
}
