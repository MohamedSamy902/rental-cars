<?php

namespace App\Services\Contracts;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Vehicle;
use App\Services\Fleet\VehicleAvailabilityService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ContractLifecycleService
{
    public function __construct(
        private readonly ContractPricingService $pricing,
        private readonly VehicleAvailabilityService $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $userId = null): Contract
    {
        return DB::transaction(function () use ($data, $userId) {
            $vehicle = Vehicle::query()->findOrFail($data['vehicle_id']);
            if (! $vehicle->is_active) {
                throw new DomainException('السيارة غير نشطة.');
            }

            $number = $data['number'] ?? $this->nextNumber();

            $contract = Contract::query()->create([
                'number' => $number,
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'guarantor_customer_id' => $data['guarantor_customer_id'] ?? null,
                'status' => $data['status'] ?? 'pending',
                'starts_at' => $data['starts_at'],
                'planned_ends_at' => $data['planned_ends_at'],
                'min_days' => $data['min_days'] ?? null,
                'frozen_daily_rate' => $data['daily_rate'] ?? $vehicle->daily_rate,
                'frozen_daily_km_allowance' => $data['daily_km_allowance'] ?? 200,
                'frozen_excess_km_rate' => $data['excess_km_rate'] ?? 5,
                'frozen_agreed_deposit' => $data['deposit'] ?? $vehicle->default_deposit,
                'frozen_operating_insurance_fee' => $data['operating_insurance_fee'] ?? null,
                'discount' => $data['discount'] ?? 0,
                'other_fees' => $data['other_fees'] ?? 0,
                'other_fees_description' => $data['other_fees_description'] ?? null,
                'sales_user_id' => $userId,
                'notes' => $data['notes'] ?? null,
                'extended_from_contract_id' => $data['extended_from_contract_id'] ?? null,
                'swapped_from_contract_id' => $data['swapped_from_contract_id'] ?? null,
                'swap_reason' => $data['swap_reason'] ?? null,
            ]);

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'created',
                'record_type' => Contract::class,
                'record_id' => $contract->id,
                'after' => $contract->toArray(),
            ]);

            return $contract->load(['customer', 'vehicle']);
        });
    }

    public function approve(Contract $contract, ?int $userId = null): Contract
    {
        if (! in_array($contract->status, ['pending', 'approved'], true)) {
            throw new DomainException('لا يمكن اعتماد هذا العقد في حالته الحالية.');
        }

        $frozen = $this->pricing;
        $days = $frozen->countRentalDays($contract->starts_at, $contract->planned_ends_at);

        $contract->fill([
            'status' => 'approved',
            'approved_days' => $days,
            'frozen_min_days' => $contract->min_days,
            'allowed_km' => $frozen->allowedKilometers(
                (int) $contract->frozen_daily_km_allowance,
                $days,
            ),
        ])->save();

        AuditLog::query()->create([
            'user_id' => $userId,
            'action' => 'approved',
            'record_type' => Contract::class,
            'record_id' => $contract->id,
            'after' => ['status' => 'approved', 'approved_days' => $days],
        ]);

        return $contract->fresh(['customer', 'vehicle']);
    }

    public function activateOnHandover(Contract $contract, int $odometerOut, ?int $userId = null): Contract
    {
        if (! in_array($contract->status, ['approved', 'pending'], true)) {
            throw new DomainException('التسليم يتطلب عقداً معتمداً أو معلّقاً قابلاً للتفعيل.');
        }

        if ($contract->status === 'pending') {
            $this->approve($contract, $userId);
            $contract->refresh();
        }

        $contract->fill([
            'status' => 'active',
            'odometer_out' => $odometerOut,
        ])->save();

        return $contract->fresh(['customer', 'vehicle']);
    }

    public function completeOnReturn(Contract $contract, int $odometerIn, ?\DateTimeInterface $returnedAt = null, ?int $userId = null): Contract
    {
        if ($contract->status !== 'active') {
            throw new DomainException('الاستلام يتطلب عقداً نشطاً.');
        }

        $end = $returnedAt ?? now();
        $days = $this->pricing->countRentalDays($contract->starts_at, $end);
        $minDays = (int) ($contract->frozen_min_days ?? $contract->min_days ?? 0);
        $billableDays = max($days, $minDays);

        $plannedEnd = $contract->planned_ends_at;
        $lateDays = 0;
        if ($end > $plannedEnd) {
            $lateDays = $this->pricing->countRentalDays($plannedEnd, $end);
        }

        $periodRent = $billableDays * (int) $contract->frozen_daily_rate;
        $allowed = $this->pricing->allowedKilometers((int) $contract->frozen_daily_km_allowance, $billableDays);
        $driven = max(0, $odometerIn - (int) $contract->odometer_out);
        $excess = max(0, $driven - $allowed);
        $excessAmount = $excess * (int) $contract->frozen_excess_km_rate;

        $final = $this->pricing->finalTotal([
            'period_rent' => $periodRent,
            'discount' => (int) $contract->discount,
            'excess_km_fee' => $excessAmount,
            'damage_fee' => (int) $contract->damage_fee,
            'operating_insurance_fee' => (int) ($contract->frozen_operating_insurance_fee ?? 0),
            'other_fees' => (int) $contract->other_fees,
        ]);

        $contract->fill([
            'status' => 'completed',
            'actual_ends_at' => $end,
            'approved_days' => $billableDays,
            'tagged_late_days' => $lateDays,
            'odometer_in' => $odometerIn,
            'allowed_km' => $allowed,
            'excess_km' => $excess,
            'excess_km_amount' => $excessAmount,
            'period_rent' => $periodRent,
            'final_total' => (int) round($final),
        ])->save();

        AuditLog::query()->create([
            'user_id' => $userId,
            'action' => 'completed',
            'record_type' => Contract::class,
            'record_id' => $contract->id,
            'after' => [
                'approved_days' => $billableDays,
                'tagged_late_days' => $lateDays,
                'final_total' => $contract->final_total,
            ],
        ]);

        return $contract->fresh(['customer', 'vehicle']);
    }

    private function nextNumber(): string
    {
        $seq = Contract::query()->withTrashed()->count() + 1;

        return 'CNT-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
