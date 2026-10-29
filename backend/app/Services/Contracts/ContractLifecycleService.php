<?php

namespace App\Services\Contracts;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\ContractVehicleSwap;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Models\Payment;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\Accounting\AccountingService;
use App\Services\Customers\CustomerCreditService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Maintenance\WorkOrderService;
use App\Services\Notifications\NotificationDispatchService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContractLifecycleService
{
    public function __construct(
        private readonly ContractPricingService $pricing,
        private readonly VehicleAvailabilityService $availability,
        private readonly AccountingService $accounting,
        private readonly InspectionService $inspections,
        private readonly NotificationDispatchService $notifications,
        private readonly CustomerCreditService $credits,
        private readonly WorkOrderService $workOrders,
    ) {}

    private function fractionDayThreshold(): ?int
    {
        $ops = TenantSetting::query()->first()?->operational ?? [];
        if (! is_array($ops)) {
            return null;
        }
        $hours = $ops['rental_fraction_extra_day_hours'] ?? null;
        if ($hours === null || $hours === '' || (int) $hours <= 0) {
            return null;
        }

        return (int) $hours;
    }

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

    public function approve(Contract $contract, ?int $userId = null, bool $reSnapshotRatesFromVehicle = true): Contract
    {
        return DB::transaction(function () use ($contract, $userId, $reSnapshotRatesFromVehicle) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new DomainException('لا يمكن اعتماد هذا العقد في حالته الحالية.');
            }

            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->whereKey($locked->vehicle_id)->lockForUpdate()->firstOrFail();

            $this->assertDocumentsAllowApproval($locked);
            $this->assertNoOverlap(
                (int) $locked->vehicle_id,
                $locked->starts_at,
                $locked->planned_ends_at,
                $locked->id,
            );

            $days = $this->pricing->countRentalDays(
                $locked->starts_at,
                $locked->planned_ends_at,
                $this->fractionDayThreshold(),
            );

            // لحظة التجميد الرسمية = الاعتماد (ن٫٥): لقطة من أسعار السيارة الحالية
            // التمديد/التبديل يمرّر أسعاراً مُجمَّدة مسبقاً فلا نعيد اللقطة.
            $rate = $reSnapshotRatesFromVehicle ? (int) $vehicle->daily_rate : (int) $locked->frozen_daily_rate;
            $deposit = $reSnapshotRatesFromVehicle ? (int) $vehicle->default_deposit : (int) $locked->frozen_agreed_deposit;

            $locked->fill([
                'status' => 'approved',
                'approved_days' => $days,
                'frozen_daily_rate' => $rate,
                'frozen_agreed_deposit' => $deposit,
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

            $this->notifyLifecycle(
                'تم اعتماد عقد',
                "العقد {$locked->number} أصبح معتمداً.",
                $locked,
                $userId,
            );

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

            $this->notifyLifecycle(
                'تم تسليم سيارة',
                "العقد {$locked->number} أصبح نشطاً بعد التسليم.",
                $locked,
                $userId,
            );

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

            $threshold = $this->fractionDayThreshold();
            $end = $returnedAt ?? now();
            $days = $this->pricing->countRentalDays($locked->starts_at, $end, $threshold);
            $minDays = (int) ($locked->frozen_min_days ?? $locked->min_days ?? 0);
            $billableDays = max($days, $minDays);

            $plannedEnd = $locked->planned_ends_at;
            $lateDays = 0;
            if ($end > $plannedEnd) {
                $lateDays = $this->pricing->countRentalDays($plannedEnd, $end, $threshold);
            }

            $periodRent = $billableDays * (int) $locked->frozen_daily_rate;
            $allowed = $this->pricing->allowedKilometers((int) $locked->frozen_daily_km_allowance, $billableDays);
            $driven = $this->accumulatedDrivenKm($locked, $odometerIn);
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
            $finalInt = (int) round($final);

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
                'final_total' => $finalInt,
            ])->save();

            Vehicle::query()->whereKey($locked->vehicle_id)->update(['odometer' => $odometerIn]);

            $this->accounting->postContractClose($locked->fresh(), $userId);

            // Early termination surplus → client credit balance (P0 / 3.5 · 8.2).
            if ($end < $plannedEnd) {
                $paidIn = (int) Payment::query()
                    ->where('link_type', 'contract')
                    ->where('link_id', $locked->id)
                    ->where('direction', 'in')
                    ->sum('amount');
                $paidOut = (int) Payment::query()
                    ->where('link_type', 'contract')
                    ->where('link_id', $locked->id)
                    ->where('direction', 'out')
                    ->sum('amount');
                $netPaid = max(0, $paidIn - $paidOut);
                $surplus = $netPaid - $finalInt;
                if ($surplus > 0) {
                    $customer = Customer::query()->findOrFail($locked->customer_id);
                    $this->credits->credit($customer, $surplus, [
                        'reason' => 'early_termination',
                        'contract_id' => $locked->id,
                        'notes' => 'فائض إنهاء مبكر للعقد '.$locked->number,
                    ], $userId);
                }
            }

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

            $this->notifyLifecycle(
                'تم استلام سيارة',
                "العقد {$locked->number} أُقفل. الإجمالي: {$locked->final_total} ج.م.",
                $locked,
                $userId,
            );

            return $locked->fresh(['customer', 'vehicle', 'deposit', 'inspections.damages', 'vehicleSwaps']);
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

            $this->notifyLifecycle(
                'تم إلغاء عقد',
                "العقد {$contract->number} أُلغي. السبب: {$reason}",
                $contract,
                $userId,
            );

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
     * Fault swap on the SAME contract (client-approved 3.17): swap vehicle, log segment,
     * put old car in maintenance, keep payments/billing ledger on the original contract id.
     *
     * @param  array{
     *   replacement_vehicle_id: int,
     *   odometer_in: int,
     *   odometer_out: int,
     *   fault_description?: string,
     *   notes?: string,
     *   signature_data?: string
     * }  $data
     */
    public function swapDueToFault(Contract $source, array $data, ?int $userId = null): Contract
    {
        if ($source->status !== 'active') {
            throw new DomainException('التبديل بسبب عطل يتطلب عقداً نشطاً.');
        }

        $replacementId = (int) $data['replacement_vehicle_id'];
        if ($replacementId === (int) $source->vehicle_id) {
            throw new DomainException('السيارة البديلة يجب أن تختلف عن السيارة الحالية.');
        }

        $odometerIn = (int) $data['odometer_in'];
        $odometerOut = (int) $data['odometer_out'];
        $segmentStart = $this->currentSegmentStartOdometer($source);
        if ($odometerIn < $segmentStart) {
            throw new DomainException('عداد إرجاع السيارة القديمة أقل من عداد بداية الحيازة.');
        }

        return DB::transaction(function () use ($source, $data, $userId, $replacementId, $odometerIn, $odometerOut, $segmentStart) {
            $locked = Contract::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            $this->assertNoOverlap(
                $replacementId,
                now(),
                $locked->planned_ends_at,
                $locked->id,
            );

            $replacement = Vehicle::query()->findOrFail($replacementId);
            if (! $replacement->is_active) {
                throw new DomainException('السيارة البديلة غير نشطة.');
            }
            if ($replacement->manual_status === VehicleAvailabilityService::STATUS_MAINTENANCE) {
                throw new DomainException('السيارة البديلة في صيانة.');
            }
            if ($odometerOut < (int) $replacement->odometer) {
                throw new DomainException('عداد خروج البديلة أقل من عدادها المسجّل.');
            }

            $oldVehicleId = (int) $locked->vehicle_id;

            Vehicle::query()->whereKey($oldVehicleId)->update([
                'odometer' => $odometerIn,
                'manual_status' => VehicleAvailabilityService::STATUS_MAINTENANCE,
            ]);

            $wo = $this->workOrders->open([
                'vehicle_id' => $oldVehicleId,
                'odometer' => $odometerIn,
                'puts_in_maintenance' => true,
                'force_after_resolution' => true,
                'workshop' => 'تبديل بسبب عطل — '.$locked->number,
                'notes' => $data['fault_description'] ?? 'عطل أثناء الإيجار',
                'labor_cost' => 0,
            ], [[
                'description' => 'فحص عطل بعد التبديل من العقد '.$locked->number,
                'part_source' => 'purchase',
                'quantity' => 1,
                'unit_price' => 0,
            ]], $userId);

            ContractVehicleSwap::query()->create([
                'contract_id' => $locked->id,
                'from_vehicle_id' => $oldVehicleId,
                'to_vehicle_id' => $replacementId,
                'segment_start_odometer' => $segmentStart,
                'odometer_in' => $odometerIn,
                'odometer_out' => $odometerOut,
                'reason' => 'عطل',
                'fault_description' => $data['fault_description'] ?? null,
                'work_order_id' => $wo->id,
                'performed_by' => $userId,
                'swapped_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $locked->fill([
                'vehicle_id' => $replacementId,
                'swap_reason' => 'عطل',
                // Current segment start for the replacement vehicle.
                'odometer_out' => $odometerOut,
                'notes' => trim(($locked->notes ? $locked->notes."\n" : '').'تم تبديل سيارة بسبب عطل'),
            ])->save();

            Vehicle::query()->whereKey($replacementId)->update(['odometer' => $odometerOut]);

            $this->inspections->record($locked->id, [
                'type' => 'handover',
                'odometer' => $odometerOut,
                'fuel_level' => (int) ($data['fuel_level'] ?? 50),
                'notes' => 'تسليم البديلة عند التبديل بسبب عطل',
                'signature_data' => $data['signature_data'] ?? ('swap-ack:'.str_repeat('0', 48)),
                'accessories' => $data['accessories'] ?? [],
                'damages' => $data['damages'] ?? [],
            ], $userId);

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'vehicle_swapped_fault',
                'record_type' => Contract::class,
                'record_id' => $locked->id,
                'after' => [
                    'from_vehicle_id' => $oldVehicleId,
                    'to_vehicle_id' => $replacementId,
                    'odometer_in' => $odometerIn,
                    'odometer_out' => $odometerOut,
                    'same_contract' => true,
                ],
            ]);

            return $locked->fresh(['customer', 'vehicle', 'deposit', 'vehicleSwaps', 'inspections.damages']);
        });
    }

    private function currentSegmentStartOdometer(Contract $contract): int
    {
        $lastSwap = ContractVehicleSwap::query()
            ->where('contract_id', $contract->id)
            ->orderByDesc('id')
            ->first();

        return (int) ($lastSwap?->odometer_out ?? $contract->odometer_out ?? 0);
    }

    private function accumulatedDrivenKm(Contract $contract, int $finalOdometerIn): int
    {
        $swaps = ContractVehicleSwap::query()
            ->where('contract_id', $contract->id)
            ->orderBy('id')
            ->get();

        $driven = 0;
        foreach ($swaps as $swap) {
            $driven += $swap->drivenKm();
        }

        $segmentStart = $this->currentSegmentStartOdometer($contract);
        $driven += max(0, $finalOdometerIn - $segmentStart);

        return $driven;
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
        if (Schema::hasTable('contract_number_sequences')) {
            $row = DB::table('contract_number_sequences')->where('id', 1)->lockForUpdate()->first();
            if (! $row) {
                DB::table('contract_number_sequences')->insert(['id' => 1, 'next_value' => 2, 'updated_at' => now(), 'created_at' => now()]);
                $seq = 1;
            } else {
                $seq = (int) $row->next_value;
                DB::table('contract_number_sequences')->where('id', 1)->update([
                    'next_value' => $seq + 1,
                    'updated_at' => now(),
                ]);
            }

            return 'CNT-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
        }

        $last = Contract::query()->withTrashed()->orderByDesc('id')->lockForUpdate()->value('number');
        $seq = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return 'CNT-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    private function notifyLifecycle(string $title, string $body, Contract $contract, ?int $actorId = null): void
    {
        try {
            $recipients = User::query()
                ->where('is_active', true)
                ->whereHas('role.permissions', fn ($q) => $q->where('key', 'contracts.view'))
                ->when($actorId, fn ($q) => $q->where('id', '!=', $actorId))
                ->limit(40)
                ->get();

            if ($contract->sales_user_id) {
                $sales = User::query()->find($contract->sales_user_id);
                if ($sales && $sales->is_active && (! $actorId || $sales->id !== $actorId)) {
                    $recipients = $recipients->push($sales)->unique('id');
                }
            }

            foreach ($recipients as $user) {
                $this->notifications->notify($user, $title, $body, 'contract', (int) $contract->id);
            }
        } catch (\Throwable) {
            // الإشعار لا يفشل العملية الأساسية
        }
    }
}
