<?php

namespace App\Services\Contracts;

/**
 * Day-count and final totals — business rules from معايير الملزمة ن٫٢–ن٫٤ / RSD 3.1–3.3.
 */
class ContractPricingService
{
    /**
     * Full rental days: any extension past start clock time counts as a full extra day.
     * Example: 1st 10:00 → 2nd 11:00 = 2 days.
     */
    public function countRentalDays(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        if ($end <= $start) {
            return 0;
        }

        $seconds = $end->getTimestamp() - $start->getTimestamp();
        $daySeconds = 24 * 60 * 60;

        return (int) max(1, (int) ceil($seconds / $daySeconds));
    }

    /**
     * Allowed kilometers at close = daily allowance × approved rental days.
     */
    public function allowedKilometers(int $dailyAllowance, int $approvedDays): int
    {
        return max(0, $dailyAllowance) * max(0, $approvedDays);
    }

    /**
     * Final total = period rent (incl. tagged late days) − discount + excess km + damages + operating insurance + other fees.
     * Cash deposit is outside this total.
     *
     * @param  array{
     *     period_rent: int|float,
     *     discount?: int|float,
     *     excess_km_fee?: int|float,
     *     damage_fee?: int|float,
     *     operating_insurance_fee?: int|float,
     *     other_fees?: int|float
     * }  $parts
     */
    public function finalTotal(array $parts): float
    {
        $period = (float) ($parts['period_rent'] ?? 0);
        $discount = (float) ($parts['discount'] ?? 0);
        $excess = (float) ($parts['excess_km_fee'] ?? 0);
        $damage = (float) ($parts['damage_fee'] ?? 0);
        $operating = (float) ($parts['operating_insurance_fee'] ?? 0);
        $other = (float) ($parts['other_fees'] ?? 0);

        return $period - $discount + $excess + $damage + $operating + $other;
    }
}
