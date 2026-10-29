<?php

namespace App\Services\Contracts;

/**
 * Day-count and final totals — client-approved rules (Oct 2026):
 * full days only; no unconditional ceil after the start clock.
 */
class ContractPricingService
{
    /**
     * Full rental days without auto-billing an extra day for a fractional
     * remainder past the start hour. Optional threshold (hours): when the
     * remainder exceeds it, one extra day is added (tenant setting).
     *
     * Examples (threshold null/0):
     * - 1 Oct 10:00 → 2 Oct 10:00 = 1 day
     * - 1 Oct 10:00 → 2 Oct 11:00 = 1 day (fraction does NOT force day 2)
     * - same calendar day with any positive duration = 1 day
     */
    public function countRentalDays(
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        ?int $fractionExtraDayAfterHours = null,
    ): int {
        if ($end <= $start) {
            return 0;
        }

        $seconds = $end->getTimestamp() - $start->getTimestamp();
        $daySeconds = 24 * 60 * 60;
        $fullDays = intdiv($seconds, $daySeconds);
        $remainder = $seconds % $daySeconds;

        $days = $fullDays;
        if ($fractionExtraDayAfterHours !== null && $fractionExtraDayAfterHours > 0 && $remainder > ($fractionExtraDayAfterHours * 3600)) {
            $days++;
        }

        return (int) max(1, $days);
    }

    /**
     * Allowed kilometers at close = daily allowance × approved rental days.
     */
    public function allowedKilometers(int $dailyAllowance, int $approvedDays): int
    {
        return max(0, $dailyAllowance) * max(0, $approvedDays);
    }

    /**
     * Final total = period rent − discount + excess km + damages + operating insurance + other fees.
     * Cash deposit is outside this total. No separate late fee / fuel differential.
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
