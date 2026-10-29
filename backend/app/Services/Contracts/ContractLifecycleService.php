<?php

namespace App\Services\Contracts;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Models\Payment;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\Accounting\AccountingService;
use App\Services\Fleet\VehicleAvailabilityService;
use DomainException;
use Illuminate\Support\Facades\DB;

class ContractLifecycleService
{
    public function __construct(
        private readonly ContractPricingService $pricing,
        private readonly VehicleAvailabilityService $availability,
        private readonly AccountingService $accounting,
        private readonly InspectionService $inspections,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $userId = null, bool $allowPriceOverride = false): Contract
    {
        return DB::transaction(function () use ($data, $userId, $allowPriceOverride) {
            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->whereKey($data['vehicle_id'])->lockForUpdate()->firstOrFail();
            if (! $vehicle->is_active) {
                throw new DomainException('السيارة غير نشطة.');
            }
            if (in_array($vehicle->manual_status, [
                VehicleAvailabilityService::STATUS_MAINTENANCE,
                VehicleAvailabilityService::STATUS_OUT_OF_SERVICE,
                VehicleAvailabilityService::STATUS_SOLD,
            ], true)) {
                throw new DomainException('السيارة غير متاحة للحجز في حالتها الحالية.');
            }

            $customer = Customer::query()->findOrFail($data['customer_id']);
            if (in_array($customer->status, ['blacklist', 'blacklisted'], true)) {
                throw new DomainException('العميل في القائمة السوداء.');
            }

            $this->assertNoOverlap(
                (int) $data['vehicle_id'],
                $data['starts_at'],
                $data['planned_ends_at'],
            );

            $number = $data['number'] ?? $this->nextNumber();

            // الأسعار تُقترح من السيارة؛ التعديل عند الإنشاء يتطلب صلاحية تجاوز السعر.
            $dailyRate = $allowPriceOverride && isset($data['daily_rate'])
                ? (int) $data['daily_rate']
                : (int) $vehicle->daily_rate;
            $deposit = $allowPriceOverride && isset($data['deposit'])
                ? (int) $data['deposit']
                : (int) $vehicle->default_deposit;
            $kmAllowance = $allowPriceOverride && isset($data['daily_km_allowance'])
                ? (int) $data['daily_km_allowance']
                : 200;
            $excessRate = $allowPriceOverride && isset($data['excess_km_rate'])
                ? (int) $data['excess_km_rate']
                : 5;

            $contract = Contract::query()->create([
                'number' => $number,
                'customer_id' => $data['customer_id'],
                'vehicle_id' => $data['vehicle_id'],
                'guarantor_customer_id' => $data['guarantor_customer_id'] ?? null,
                'status' => $data['status'] ?? 'pending',
                'starts_at' => $data['starts_at'],
                'planned_ends_at' => $data['planned_ends_at'],
                'min_days' => $data['min_days'] ?? null,
                'frozen_daily_rate' => $dailyRate,
                'frozen_daily_km_allowance' => $kmAllowance,
                'frozen_excess_km_rate' => $excessRate,
                'frozen_agreed_deposit' => $deposit,
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
        return DB::transaction(function () use ($contract, $userId) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new DomainException('لا يمكن اعتماد هذا العقد في حالته الحالية.');
            }

            Vehicle::query()->whereKey($locked->vehicle_id)->lockForUpdate()->firstOrFail();

            $this->assertDocumentsAllowApproval($locked);
            $this->assertNoOverlap(
                (int) $locked->vehicle_id,
                $locked->starts_at,
                $locked->planned_ends_at,
                $locked->id,
            );

            $days = $this->pricing->countRentalDays($locked->starts_at, $locked->planned_ends_at);

            // لحظة التجميد الرسمية = الاعتماد (ن٫٥)
            $locked->fill([
                'status' => 'approved',
                'approved_days' => $days,
                'frozen_min_days' => $locked->min_days,
                'allowed_km' => $this->pricing->allowedKilometers(
                    (int) $locked->frozen_daily_km_allowance,
                    $days,
                ),
            ])->save();

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'approved',
                'record_type' => Contract::class,
                'record_id' => $locked->id,
                'after' => [
                    'status' => 'approved',
                    'approved_days' => $days,
                    'frozen_daily_rate' => $locked->frozen_daily_rate,
                    'frozen_agreed_deposit' => $locked->frozen_agreed_deposit,
                ],
            ]);

            return $locked->fresh(['customer', 'vehicle', 'deposit']);
        });
    }

    /**
     * تعديل أسعار بعد الاعتماد — يتطلب صلاحية contracts_price.override.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateFrozenPricing(Contract $contract, array $data, ?int $userId = null): Contract
    {
        return DB::transaction(function () use ($contract, $data, $userId) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['approved', 'active'], true)) {
                throw new DomainException('تعديل الأسعار متاح للعقود المعتمدة أو النشطة فقط.');
            }

            $before = [
                'frozen_daily_rate' => $locked->frozen_daily_rate,
                'frozen_daily_km_allowance' => $locked->frozen_daily_km_allowance,
                'frozen_excess_km_rate' => $locked->frozen_excess_km_rate,
                'frozen_agreed_deposit' => $locked->frozen_agreed_deposit,
                'discount' => $locked->discount,
                'other_fees' => $locked->other_fees,
            ];

            $locked->fill([
                'frozen_daily_rate' => $data['daily_rate'] ?? $locked->frozen_daily_rate,
                'frozen_daily_km_allowance' => $data['daily_km_allowance'] ?? $locked->frozen_daily_km_allowance,
                'frozen_excess_km_rate' => $data['excess_km_rate'] ?? $locked->frozen_excess_km_rate,
                'frozen_agreed_deposit' => $data['deposit'] ?? $locked->frozen_agreed_deposit,
                'discount' => $data['discount'] ?? $locked->discount,
                'other_fees' => $data['other_fees'] ?? $locked->other_fees,
                'other_fees_description' => $data['other_fees_description'] ?? $locked->other_fees_description,
            ])->save();

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'price_override',
                'record_type' => Contract::class,
                'record_id' => $locked->id,
                'before' => $before,
                'after' => [
                    'frozen_daily_rate' => $locked->frozen_daily_rate,
                    'frozen_daily_km_allowance' => $locked->frozen_daily_km_allowance,
                    'frozen_excess_km_rate' => $locked->frozen_excess_km_rate,
                    'frozen_agreed_deposit' => $locked->frozen_agreed_deposit,
                    'discount' => $locked->discount,
                    'other_fees' => $locked->other_fees,
                ],
            ]);

            return $locked->fresh(['customer', 'vehicle', 'deposit']);
        });
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    public function activateOnHandover(Contract $contract, int $odometerOut, ?int $userId = null, array $inspection = []): Contract
    {
        if (! in_array($contract->status, ['approved', 'pending'], true)) {
            throw new DomainException('التسليم يتطلب عقداً معتمداً أو معلّقاً قابلاً للتفعيل.');
        }

        if ($contract->status === 'pending') {
            $contract = $this->approve($contract, $userId);
        }

        return DB::transaction(function () use ($contract, $odometerOut, $userId, $inspection) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'approved') {
                throw new DomainException('التسليم يتطلب عقداً معتمداً.');
            }

            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->whereKey($locked->vehicle_id)->lockForUpdate()->firstOrFail();

            $otherActive = Contract::query()
                ->where('vehicle_id', $locked->vehicle_id)
                ->where('status', 'active')
                ->where('id', '!=', $locked->id)
                ->lockForUpdate()
                ->exists();
            if ($otherActive) {
                throw new DomainException('لا يمكن التسليم: توجد حيازة نشطة أخرى على نفس السيارة.');
            }

            $this->assertNoOverlap(
                (int) $locked->vehicle_id,
                $locked->starts_at,
                $locked->planned_ends_at,
                $locked->id,
            );

            if ($odometerOut < (int) $vehicle->odometer) {
                throw new DomainException('عداد الخروج أقل من عداد السيارة المسجّل.');
            }

            $this->inspections->record($locked->id, [
                'type' => 'handover',
                'odometer' => $odometerOut,
                'fuel_level' => (int) ($inspection['fuel_level'] ?? 50),
                'notes' => $inspection['notes'] ?? null,
                'signature_data' => $inspection['signature_data'] ?? null,
                'accessories' => $inspection['accessories'] ?? [],
                'damages' => $inspection['damages'] ?? [],
            ], $userId);

            $locked->fill([
                'status' => 'active',
                'odometer_out' => $odometerOut,
            ])->save();

            $vehicle->update(['odometer' => $odometerOut]);

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'handover',
                'record_type' => Contract::class,
                'record_id' => $locked->id,
                'after' => ['status' => 'active', 'odometer_out' => $odometerOut],
            ]);

            return $locked->fresh(['customer', 'vehicle', 'deposit', 'inspections.damages']);
        });
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    public function completeOnReturn(
        Contract $contract,
        int $odometerIn,
        ?\DateTimeInterface $returnedAt = null,
        ?int $userId = null,
        int $damageFee = 0,
        array $inspection = [],
    ): Contract {
        return DB::transaction(function () use ($contract, $odometerIn, $returnedAt, $userId, $damageFee, $inspection) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new DomainException('الاستلام يتطلب عقداً نشطاً.');
            }
            if ($odometerIn < (int) $locked->odometer_out) {
                throw new DomainException('عداد العودة أقل من عداد الخروج.');
            }

            Vehicle::query()->whereKey($locked->vehicle_id)->lockForUpdate()->firstOrFail();

            $this->inspections->record($locked->id, [
                'type' => 'return',
                'odometer' => $odometerIn,
                'fuel_level' => (int) ($inspection['fuel_level'] ?? 50),
                'notes' => $inspection['notes'] ?? null,
                'signature_data' => $inspection['signature_data'] ?? null,
                'accessories' => $inspection['accessories'] ?? [],
                'damages' => $inspection['damages'] ?? [],
                'damage_fee' => $damageFee,
            ], $userId);

            $end = $returnedAt ?? now();
            $days = $this->pricing->countRentalDays($locked->starts_at, $end);
            $minDays = (int) ($locked->frozen_min_days ?? $locked->min_days ?? 0);
            $billableDays = max($days, $minDays);

            $plannedEnd = $locked->planned_ends_at;
            $lateDays = 0;
            if ($end > $plannedEnd) {
                $lateDays = $this->pricing->countRentalDays($plannedEnd, $end);
            }

            $periodRent = $billableDays * (int) $locked->frozen_daily_rate;
            $allowed = $this->pricing->allowedKilometers((int) $locked->frozen_daily_km_allowance, $billableDays);
            $driven = max(0, $odometerIn - (int) $locked->odometer_out);
            $excess = max(0, $driven - $allowed);
            $excessAmount = $excess * (int) $locked->frozen_excess_km_rate;

            $final = $this->pricing->finalTotal([
                'period_rent' => $periodRent,
                'discount' => (int) $locked->discount,
                'excess_km_fee' => $excessAmount,
                'damage_fee' => $damageFee,
                'operating_insurance_fee' => (int) ($locked->frozen_operating_insurance_fee ?? 0),
                'other_fees' => (int) $locked->other_fees,
            ]);

            $locked->fill([
                'status' => 'completed',
                'actual_ends_at' => $end,
                'approved_days' => $billableDays,
                'tagged_late_days' => $lateDays,
                'odometer_in' => $odometerIn,
                'allowed_km' => $allowed,
                'excess_km' => $excess,
                'excess_km_amount' => $excessAmount,
                'period_rent' => $periodRent,
                'damage_fee' => $damageFee,
                'final_total' => (int) round($final),
            ])->save();

            Vehicle::query()->whereKey($locked->vehicle_id)->update(['odometer' => $odometerIn]);

            $this->accounting->postContractClose($locked->fresh(), $userId);

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'completed',
                'record_type' => Contract::class,
                'record_id' => $locked->id,
                'after' => [
                    'approved_days' => $billableDays,
                    'tagged_late_days' => $lateDays,
                    'final_total' => $locked->final_total,
                ],
            ]);

            return $locked->fresh(['customer', 'vehicle', 'deposit', 'inspections.damages']);
        });
    }

    public function cancel(Contract $contract, string $reason, ?int $userId = null): Contract
    {
        if (! in_array($contract->status, ['pending', 'approved'], true)) {
            throw new DomainException('بعد التسليم يُستخدم إنهاء مبكر أو استلام — لا إلغاء.');
        }
        if (trim($reason) === '') {
            throw new DomainException('سبب الإلغاء إلزامي.');
        }

        return DB::transaction(function () use ($contract, $reason, $userId) {
            $contract->fill([
                'status' => 'cancelled',
                'cancel_reason' => $reason,
            ])->save();

            $deposit = $contract->deposit;
            if ($deposit && $deposit->status === 'received') {
                $this->returnDeposit($deposit, 'cash', 'CXL-'.$contract->number, $userId);
            }

            Payment::query()
                ->where('link_type', 'contract')
                ->where('link_id', $contract->id)
                ->where('direction', 'in')
                ->get()
                ->each(function (Payment $payment) use ($userId) {
                    $this->accounting->recordPayment([
                        'amount' => $payment->amount,
                        'method' => $payment->method,
                        'direction' => 'out',
                        'reference' => 'REFUND-'.$payment->id,
                        'link_type' => $payment->link_type,
                        'link_id' => $payment->link_id,
                        'notes' => 'رد عند إلغاء العقد قبل التسليم',
                    ], $userId);
                });

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'cancelled',
                'record_type' => Contract::class,
                'record_id' => $contract->id,
                'after' => ['status' => 'cancelled', 'reason' => $reason],
            ]);

            return $contract->fresh(['customer', 'vehicle', 'deposit']);
        });
    }

    /**
     * Extension = brand-new signed contract (RSD 3.19).
     *
     * @param  array{planned_ends_at: string, starts_at?: string}  $data
     */
    public function extend(Contract $source, array $data, ?int $userId = null): Contract
    {
        if (! in_array($source->status, ['approved', 'active', 'completed'], true)) {
            throw new DomainException('لا يمكن التمديد من هذه الحالة.');
        }

        $starts = $data['starts_at'] ?? $source->planned_ends_at->format('Y-m-d H:i:s');

        return $this->create([
            'customer_id' => $source->customer_id,
            'vehicle_id' => $source->vehicle_id,
            'guarantor_customer_id' => $source->guarantor_customer_id,
            'starts_at' => $starts,
            'planned_ends_at' => $data['planned_ends_at'],
            'daily_rate' => $source->frozen_daily_rate,
            'daily_km_allowance' => $source->frozen_daily_km_allowance,
            'excess_km_rate' => $source->frozen_excess_km_rate,
            'deposit' => $source->frozen_agreed_deposit,
            'operating_insurance_fee' => $source->frozen_operating_insurance_fee,
            'min_days' => $source->frozen_min_days ?? $source->min_days,
            'notes' => trim(($source->notes ? $source->notes."\n" : '').'تمديد من '.$source->number),
            'extended_from_contract_id' => $source->id,
        ], $userId, true);
    }

    /**
     * Fault swap: close old possession + new contract for replacement (RSD 3.17).
     *
     * @param  array{replacement_vehicle_id: int, odometer_in: int, odometer_out: int, planned_ends_at?: string}  $data
     * @return array{old: Contract, new: Contract}
     */
    public function swapDueToFault(Contract $source, array $data, ?int $userId = null): array
    {
        if ($source->status !== 'active') {
            throw new DomainException('التبديل بسبب عطل يتطلب عقداً نشطاً.');
        }

        return DB::transaction(function () use ($source, $data, $userId) {
            $old = $this->completeOnReturn(
                $source,
                (int) $data['odometer_in'],
                now(),
                $userId,
                (int) ($data['damage_fee'] ?? 0),
            );

            Vehicle::query()->whereKey($old->vehicle_id)->update([
                'manual_status' => VehicleAvailabilityService::STATUS_MAINTENANCE,
            ]);

            $remainingDeposit = $old->deposit?->remaining() ?? (int) $old->frozen_agreed_deposit;

            $new = $this->create([
                'customer_id' => $old->customer_id,
                'vehicle_id' => $data['replacement_vehicle_id'],
                'guarantor_customer_id' => $old->guarantor_customer_id,
                'starts_at' => now()->format('Y-m-d H:i:s'),
                'planned_ends_at' => $data['planned_ends_at'] ?? $old->planned_ends_at->format('Y-m-d H:i:s'),
                'daily_rate' => $old->frozen_daily_rate,
                'daily_km_allowance' => $old->frozen_daily_km_allowance,
                'excess_km_rate' => $old->frozen_excess_km_rate,
                'deposit' => $remainingDeposit,
                'operating_insurance_fee' => $old->frozen_operating_insurance_fee,
                'min_days' => $old->frozen_min_days ?? $old->min_days,
                'notes' => 'تبديل بسبب عطل من '.$old->number,
                'swapped_from_contract_id' => $old->id,
                'swap_reason' => 'عطل',
                'status' => 'pending',
            ], $userId, true);

            $new = $this->approve($new, $userId);
            $handoverInspection = [
                'signature_data' => $data['signature_data'] ?? ('swap-handover-ack:'.str_repeat('0', 48)),
                'fuel_level' => (int) ($data['fuel_level'] ?? 50),
                'notes' => 'تسليم تلقائي عند التبديل بسبب عطل',
                'damages' => $data['damages'] ?? [],
                'accessories' => $data['accessories'] ?? [],
            ];
            $new = $this->activateOnHandover($new, (int) $data['odometer_out'], $userId, $handoverInspection);

            if ($remainingDeposit > 0) {
                $this->receiveDeposit($new, [
                    'amount' => $remainingDeposit,
                    'method' => 'transfer',
                    'receipt_number' => 'TRF-'.$old->number,
                    'collection_ref' => 'ترحيل وديعة من '.$old->number,
                ], $userId);
            }

            return ['old' => $old->fresh(['customer', 'vehicle', 'deposit']), 'new' => $new->fresh(['customer', 'vehicle', 'deposit'])];
        });
    }

    /**
     * @param  array{amount?: int, method: string, receipt_number: string, collection_ref?: string}  $data
     */
    public function receiveDeposit(Contract $contract, array $data, ?int $userId = null): Deposit
    {
        return DB::transaction(function () use ($contract, $data, $userId) {
            Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if (Deposit::query()->where('contract_id', $contract->id)->lockForUpdate()->exists()) {
                throw new DomainException('يوجد تأمين نقدي مسجّل لهذا العقد.');
            }

            $amount = (int) ($data['amount'] ?? $contract->frozen_agreed_deposit);
            if ($amount <= 0) {
                throw new DomainException('مبلغ التأمين النقدي غير صالح.');
            }

            $deposit = Deposit::query()->create([
                'contract_id' => $contract->id,
                'original_amount' => $amount,
                'status' => 'received',
                'received_at' => now(),
                'receipt_number' => $data['receipt_number'],
                'collection_method' => $data['method'],
                'collection_ref' => $data['collection_ref'] ?? null,
                'deductions_total' => 0,
            ]);

            $this->accounting->recordPayment([
                'amount' => $amount,
                'method' => $data['method'],
                'direction' => 'in',
                'reference' => $data['receipt_number'],
                'link_type' => 'deposit',
                'link_id' => $deposit->id,
                'notes' => 'قبض تأمين نقدي للعقد '.$contract->number,
            ], $userId);

            return $deposit;
        });
    }

    /**
     * @param  array{reason: string, amount: int}  $data
     */
    public function deductDeposit(Deposit $deposit, array $data, int $userId): Deposit
    {
        if (! in_array($deposit->status, ['received', 'partial'], true)) {
            throw new DomainException('لا يمكن الخصم من هذا التأمين.');
        }
        $amount = (int) $data['amount'];
        if ($amount <= 0 || $amount > $deposit->remaining()) {
            throw new DomainException('مبلغ الخصم غير صالح.');
        }

        DepositDeduction::query()->create([
            'deposit_id' => $deposit->id,
            'reason' => $data['reason'],
            'amount' => $amount,
            'user_id' => $userId,
            'deducted_at' => now(),
        ]);

        $deposit->deductions_total = (int) $deposit->deductions_total + $amount;
        $deposit->status = $deposit->remaining() === 0 ? 'settled' : 'partial';
        $deposit->save();

        return $deposit->fresh('deductions');
    }

    public function returnDeposit(Deposit $deposit, string $method, string $receiptNumber, ?int $userId = null): Deposit
    {
        if (! in_array($deposit->status, ['received', 'partial'], true)) {
            throw new DomainException('لا يمكن رد هذا التأمين.');
        }

        $remaining = $deposit->remaining();
        $deposit->fill([
            'status' => 'returned',
            'returned_at' => now(),
            'return_method' => $method,
            'return_receipt_number' => $receiptNumber,
        ])->save();

        if ($remaining > 0) {
            $this->accounting->recordPayment([
                'amount' => $remaining,
                'method' => $method,
                'direction' => 'out',
                'reference' => $receiptNumber,
                'link_type' => 'deposit',
                'link_id' => $deposit->id,
                'notes' => 'رد متبقي التأمين النقدي',
            ], $userId);
        }

        return $deposit->fresh('deductions');
    }

    private function assertNoOverlap(int $vehicleId, mixed $startsAt, mixed $endsAt, ?int $ignoreId = null): void
    {
        $q = Contract::query()
            ->where('vehicle_id', $vehicleId)
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->where('starts_at', '<', $endsAt)
            ->where('planned_ends_at', '>', $startsAt);

        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }

        $conflict = $q->first(['id', 'number']);
        if ($conflict) {
            throw new DomainException('السيارة محجوزة بعقد متعارض: '.$conflict->number);
        }
    }

    private function assertDocumentsAllowApproval(Contract $contract): void
    {
        $settings = TenantSetting::query()->first();
        $ops = $settings?->operational ?? [];
        $blockExpiredLicense = (bool) ($ops['block_expired_license'] ?? true);
        $blockExpiredVehicleDocs = (bool) ($ops['block_expired_vehicle_docs'] ?? true);

        $customer = $contract->customer ?? Customer::query()->find($contract->customer_id);
        if ($blockExpiredLicense && $customer?->license_expires_on && $customer->license_expires_on->isPast()) {
            throw new DomainException('رخصة العميل منتهية — يُمنع الاعتماد.');
        }

        if ($customer?->license_expires_on && $customer->license_expires_on->lt($contract->planned_ends_at)) {
            throw new DomainException('رخصة العميل تنتهي قبل نهاية العقد المخططة.');
        }

        if ($blockExpiredVehicleDocs) {
            $expired = VehicleDocument::query()
                ->where('vehicle_id', $contract->vehicle_id)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<', now()->toDateString())
                ->first();
            if ($expired) {
                throw new DomainException('مستند سيارة منتهٍ ('.$expired->type.') — يُمنع الاعتماد.');
            }

            $endsBeforeContract = VehicleDocument::query()
                ->where('vehicle_id', $contract->vehicle_id)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<', $contract->planned_ends_at->toDateString())
                ->first();
            if ($endsBeforeContract) {
                throw new DomainException('مستند السيارة ('.$endsBeforeContract->type.') ينتهي قبل نهاية العقد المخططة.');
            }
        }
    }

    private function nextNumber(): string
    {
        $seq = Contract::query()->withTrashed()->count() + 1;

        return 'CNT-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
