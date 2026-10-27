<?php

namespace App\Services\Contracts;

use DomainException;

/**
 * Contract lifecycle orchestration (create / approve / activate / complete / cancel).
 * Controllers stay thin — all multi-step business lives here.
 */
class ContractService
{
    public function __construct(
        private readonly ContractPricingService $pricing,
    ) {}

    /**
     * Prices frozen at approval (or first activation if skipped pending).
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function freezePricesAtApproval(array $draft): array
    {
        $required = [
            'daily_rate',
            'daily_km_allowance',
            'excess_km_rate',
            'agreed_deposit',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $draft) || $draft[$key] === null || $draft[$key] === '') {
                throw new DomainException("لا يمكن الاعتماد بدون تثبيت الحقل: {$key}");
            }
        }

        return [
            ...$draft,
            'frozen_daily_rate' => $draft['daily_rate'],
            'frozen_daily_km_allowance' => $draft['daily_km_allowance'],
            'frozen_excess_km_rate' => $draft['excess_km_rate'],
            'frozen_agreed_deposit' => $draft['agreed_deposit'],
            'frozen_operating_insurance_fee' => $draft['operating_insurance_fee'] ?? null,
            'frozen_min_days' => $draft['min_days'] ?? null,
            'status' => 'approved',
        ];
    }

    public function pricing(): ContractPricingService
    {
        return $this->pricing;
    }
}
