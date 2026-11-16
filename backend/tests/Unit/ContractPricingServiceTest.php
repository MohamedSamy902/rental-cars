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

    public function test_fractional_day_past_start_hour_does_not_auto_bill_extra_day(): void
    {
        $start = new DateTimeImmutable('2026-10-01 10:00:00');
        $end = new DateTimeImmutable('2026-10-02 11:00:00');

        // Client amendment: no unconditional ceil — 25h = 1 day.
        $this->assertSame(1, $this->pricing->countRentalDays($start, $end));
    }

    public function test_exact_24h_is_one_day(): void
    {
        $start = new DateTimeImmutable('2026-10-01 10:00:00');
        $end = new DateTimeImmutable('2026-10-02 10:00:00');

        $this->assertSame(1, $this->pricing->countRentalDays($start, $end));
    }

    public function test_same_calendar_day_still_one_day(): void
    {
        $start = new DateTimeImmutable('2026-10-01 10:00:00');
        $end = new DateTimeImmutable('2026-10-01 18:00:00');

        $this->assertSame(1, $this->pricing->countRentalDays($start, $end));
    }

    public function test_threshold_adds_extra_day_when_remainder_exceeds_hours(): void
    {
        $start = new DateTimeImmutable('2026-10-01 10:00:00');
        $end = new DateTimeImmutable('2026-10-02 16:00:00'); // 30h → remainder 6h

        $this->assertSame(1, $this->pricing->countRentalDays($start, $end, 6)); // 6h not > 6h
        $this->assertSame(2, $this->pricing->countRentalDays($start, $end, 5)); // 6h > 5h
    }

    public function test_two_full_days(): void
    {
        $start = new DateTimeImmutable('2026-10-01 10:00:00');
        $end = new DateTimeImmutable('2026-10-03 10:00:00');

        $this->assertSame(2, $this->pricing->countRentalDays($start, $end));
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

    public function test_prepaid_excess_amount_and_billable_beyond_package(): void
    {
        $this->assertSame(5000, $this->pricing->prepaidExcessAmount(1000, 5));
        $this->assertSame(0, $this->pricing->billableExcessKilometers(800, 1000));
        $this->assertSame(200, $this->pricing->billableExcessKilometers(1200, 1000));
    }

    public function test_final_total_includes_prepaid_and_additional_excess(): void
    {
        $total = $this->pricing->finalTotal([
            'period_rent' => 3000,
            'prepaid_excess_km_fee' => 5000,
            'excess_km_fee' => 200,
        ]);

        $this->assertSame(8200.0, $total);
    }

    public function test_final_total_includes_delivery_service_fee(): void
    {
        $total = $this->pricing->finalTotal([
            'period_rent' => 3000,
            'discount' => 0,
            'delivery_service_fee' => 1000,
            'other_fees' => 0,
        ]);

        $this->assertSame(4000.0, $total);
    }

    public function test_normalize_delivery_service_clears_when_disabled(): void
    {
        $normalized = $this->pricing->normalizeDeliveryService([
            'has_delivery_service' => false,
            'delivery_service_fee' => 1000,
            'delivery_service_notes' => 'مطار',
        ]);

        $this->assertFalse($normalized['has_delivery_service']);
        $this->assertSame(0, $normalized['delivery_service_fee']);
        $this->assertNull($normalized['delivery_service_notes']);
    }

    public function test_weekly_rent_full_weeks_plus_extra_days_at_week_day_cost(): void
    {
        // أسبوعي 6000 · ٧ أيام = 6000 · ١٠ أيام = 6000 + round(3*6000/7)=6000+2571
        $this->assertSame(6000, $this->pricing->periodRent(7, 'weekly', 1000, 6000, null));
        $this->assertSame(8571, $this->pricing->periodRent(10, 'weekly', 1000, 6000, null));
        $this->assertSame(12000, $this->pricing->periodRent(14, 'weekly', 1000, 6000, null));
    }

    public function test_monthly_rent_full_months_plus_extra_days_at_month_day_cost(): void
    {
        // شهري 22000 · ٣٠ يوماً = 22000 · ٣٢ يوماً = 22000 + round(2*22000/30)=22000+1467
        $this->assertSame(22000, $this->pricing->periodRent(30, 'monthly', 1000, null, 22000));
        $this->assertSame(23467, $this->pricing->periodRent(32, 'monthly', 1000, null, 22000));
        $this->assertSame(44000, $this->pricing->periodRent(60, 'monthly', 1000, null, 22000));
    }

    public function test_daily_rent_unchanged(): void
    {
        $this->assertSame(5000, $this->pricing->periodRent(5, 'daily', 1000, 6000, 22000));
    }
}
