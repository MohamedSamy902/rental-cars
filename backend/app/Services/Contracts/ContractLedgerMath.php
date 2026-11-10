<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\TenantSetting;

/**
 * Shared contract charge / paid / remaining math for dossier + statements.
 */
class ContractLedgerMath
{
    public function __construct(private readonly ContractPricingService $pricing) {}

    /**
     * @return array{total: int, paid: int, balance: int}
     */
    public function forContract(Contract $contract): array
    {
        $paidIn = (int) Payment::query()
            ->where('link_type', 'contract')
            ->where('link_id', $contract->id)
            ->where('direction', 'in')
            ->sum('amount');
        $paidOut = (int) Payment::query()
            ->where('link_type', 'contract')
            ->where('link_id', $contract->id)
            ->where('direction', 'out')
            ->sum('amount');
        $paid = $paidIn - $paidOut;
        $total = $this->chargeTotal($contract);

        return [
            'total' => $total,
            'paid' => $paid,
            'balance' => max(0, $total - $paid),
        ];
    }

    public function chargeTotal(Contract $contract): int
    {
        $total = (int) ($contract->final_total ?? 0);
        if ($total > 0) {
            return $total;
        }

        if ($contract->period_rent !== null && (int) $contract->period_rent > 0) {
            return (int) $contract->period_rent
                + (int) $contract->excess_km_amount
                + (int) $contract->damage_fee
                + (int) ($contract->frozen_operating_insurance_fee ?? 0)
                + (int) $contract->other_fees
                + (int) ($contract->delivery_service_fee ?? 0)
                - (int) $contract->discount;
        }

        $days = max(0, (int) ($contract->approved_days ?? 0));
        if ($days <= 0 && $contract->starts_at && $contract->planned_ends_at) {
            $ops = TenantSetting::query()->first()?->operational ?? [];
            $threshold = (int) ($ops['rental_fraction_extra_day_hours'] ?? 0);
            $days = $this->pricing->countRentalDays(
                $contract->starts_at,
                $contract->planned_ends_at,
                $threshold > 0 ? $threshold : null,
            );
        }
        $days = max(1, $days);

        return $days * (int) $contract->frozen_daily_rate
            + (int) $contract->other_fees
            + (int) ($contract->delivery_service_fee ?? 0)
            - (int) $contract->discount;
    }

    public function remainingCollectible(Contract $contract): int
    {
        return $this->forContract($contract)['balance'];
    }
}
