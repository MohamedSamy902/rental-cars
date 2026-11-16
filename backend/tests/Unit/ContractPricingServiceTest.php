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

    public function test_resolve_rent_basis_tiers_by_days(): void
    {
        $this->assertSame('daily', $this->pricing->resolveRentBasisFromDays(1));
        $this->assertSame('daily', $this->pricing->resolveRentBasisFromDays(10));
        $this->assertSame('half_monthly', $this->pricing->resolveRentBasisFromDays(11));
        $this->assertSame('half_monthly', $this->pricing->resolveRentBasisFromDays(20));
        $this->assertSame('monthly', $this->pricing->resolveRentBasisFromDays(21));
        $this->assertSame('monthly', $this->pricing->resolveRentBasisFromDays(60));
    }

    public function test_period_rent_uses_day_tiers_not_stored_basis(): void
    {
        // ١–١٠ يومي حتى لو طُلب أساس قديم weekly
        $this->assertSame(10000, $this->pricing->periodRent(10, 'weekly', 1000, 15000, 22000));
        // ١١–٢٠ نص شهري (وحدة ١٥ يوماً)
        $this->assertSame(15000, $this->pricing->periodRent(15, 'daily', 1000, 15000, 22000));
        $this->assertSame(18000, $this->pricing->periodRent(18, null, 1000, 15000, 22000));
        // ٢١+ شهري (وحدة ٣٠ يوماً)
        $this->assertSame(22000, $this->pricing->periodRent(30, 'half_monthly', 1000, 15000, 22000));
        $this->assertSame(23467, $this->pricing->periodRent(32, null, 1000, 15000, 22000));
        $this->assertSame(44000, $this->pricing->periodRent(60, null, 1000, 15000, 22000));
    }

    public function test_normalize_maps_legacy_weekly_to_half_monthly(): void
    {
        $this->assertSame('half_monthly', $this->pricing->normalizeRentBasis('weekly'));
        $this->assertSame('half_monthly', $this->pricing->normalizeRentBasis('half_month'));
    }
}
