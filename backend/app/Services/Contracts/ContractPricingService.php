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
     * Tagged late days are full days past the planned end only.
     * A remainder shorter than a day is not tagged, and there is no separate penalty.
     */
    public function countLateDays(
        \DateTimeInterface $plannedEnd,
        \DateTimeInterface $actualEnd,
        ?int $fractionExtraDayAfterHours = null,
    ): int {
        if ($actualEnd <= $plannedEnd) {
            return 0;
        }

        $seconds = $actualEnd->getTimestamp() - $plannedEnd->getTimestamp();
        $daySeconds = 24 * 60 * 60;
        $fullDays = intdiv($seconds, $daySeconds);
        $remainder = $seconds % $daySeconds;
        $days = $fullDays;
        if ($fractionExtraDayAfterHours !== null && $fractionExtraDayAfterHours > 0 && $remainder > ($fractionExtraDayAfterHours * 3600)) {
            $days++;
        }

        return $days;
    }

    /**
     * Allowed kilometers at close = daily allowance × approved rental days.
     */
    public function allowedKilometers(int $dailyAllowance, int $approvedDays): int
    {
        return max(0, $dailyAllowance) * max(0, $approvedDays);
    }

    /**
     * Money for prepaid extra kilometers agreed at booking (km × rate). No refund if unused.
     */
    public function prepaidExcessAmount(int $prepaidKm, int $excessKmRate): int
    {
        return max(0, $prepaidKm) * max(0, $excessKmRate);
    }

    /**
     * Kilometers beyond the prepaid package that are billable at return.
     */
    public function billableExcessKilometers(int $actualExcessKm, int $prepaidKm): int
    {
        return max(0, $actualExcessKm - max(0, $prepaidKm));
    }

    /**
     * Final total = period rent − discount + prepaid excess km + additional excess km
     * + damages + operating insurance + other fees + delivery/pick-up service fee.
     * Cash deposit is outside this total. No separate late fee / fuel differential.
     *
     * @param  array{
     *     period_rent: int|float,
     *     discount?: int|float,
     *     prepaid_excess_km_fee?: int|float,
     *     excess_km_fee?: int|float,
     *     damage_fee?: int|float,
     *     operating_insurance_fee?: int|float,
     *     other_fees?: int|float,
     *     delivery_service_fee?: int|float
     * }  $parts
     */
    public function finalTotal(array $parts): float
    {
        $period = (float) ($parts['period_rent'] ?? 0);
        $discount = (float) ($parts['discount'] ?? 0);
        $prepaidExcess = (float) ($parts['prepaid_excess_km_fee'] ?? 0);
        $excess = (float) ($parts['excess_km_fee'] ?? 0);
        $damage = (float) ($parts['damage_fee'] ?? 0);
        $operating = (float) ($parts['operating_insurance_fee'] ?? 0);
        $other = (float) ($parts['other_fees'] ?? 0);
        $delivery = (float) ($parts['delivery_service_fee'] ?? 0);

        return $period - $discount + $prepaidExcess + $excess + $damage + $operating + $other + $delivery;
    }

    /**
     * Normalize delivery toggle + fee + notes from request/create payloads.
     *
     * @param  array<string, mixed>  $data
     * @return array{has_delivery_service: bool, delivery_service_fee: int, delivery_service_notes: ?string}
     */
    public function normalizeDeliveryService(array $data, bool $defaultEnabled = false, int $defaultFee = 0, ?string $defaultNotes = null): array
    {
        $enabled = array_key_exists('has_delivery_service', $data)
            ? filter_var($data['has_delivery_service'], FILTER_VALIDATE_BOOLEAN)
            : $defaultEnabled;

        if (! $enabled) {
            return [
                'has_delivery_service' => false,
                'delivery_service_fee' => 0,
                'delivery_service_notes' => null,
            ];
        }

        $fee = array_key_exists('delivery_service_fee', $data)
            ? max(0, (int) $data['delivery_service_fee'])
            : max(0, $defaultFee);
        $notes = array_key_exists('delivery_service_notes', $data)
            ? $data['delivery_service_notes']
            : $defaultNotes;
        if (is_string($notes)) {
            $notes = trim($notes);
            $notes = $notes === '' ? null : mb_substr($notes, 0, 255);
        } else {
            $notes = null;
        }

        return [
            'has_delivery_service' => true,
            'delivery_service_fee' => $fee,
            'delivery_service_notes' => $notes,
        ];
    }
}
