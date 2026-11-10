<?php

namespace App\Services\Contracts;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\ContractVehicleSwap;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Models\Inspection;
use App\Models\Payment;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\Violation;
use App\Services\Accounting\AccountingService;
use App\Services\Customers\CustomerCreditService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Maintenance\WorkOrderService;
use App\Services\Notifications\NotificationDispatchService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContractLifecycleService
{
    private const DEFAULT_DAILY_KM_ALLOWANCE = 200;

    private const DEFAULT_EXCESS_KM_RATE = 5;

    public function __construct(
        private readonly ContractPricingService $pricing,
        private readonly VehicleAvailabilityService $availability,
        private readonly AccountingService $accounting,
        private readonly InspectionService $inspections,
        private readonly NotificationDispatchService $notifications,
        private readonly CustomerCreditService $credits,
        private readonly WorkOrderService $workOrders,
        private readonly ContractLedgerMath $ledgerMath,
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
                throw new DomainException('العربية غير نشطة.');
            }
            if (in_array($vehicle->manual_status, [
                VehicleAvailabilityService::STATUS_MAINTENANCE,
                VehicleAvailabilityService::STATUS_OUT_OF_SERVICE,
                VehicleAvailabilityService::STATUS_SOLD,
            ], true)) {
                throw new DomainException('العربية غير متاحة للعقد في حالتها الحالية.');
            }

            $customer = Customer::query()->findOrFail($data['customer_id']);
            if (in_array($customer->status, ['blacklist', 'blacklisted'], true)) {
                throw new DomainException('العميل في القائمة السوداء.');
            }

            $this->assertOwnerAgreementCoversRental($vehicle, $data['planned_ends_at']);
            $this->assertNoOverlap(
                (int) $data['vehicle_id'],
                $data['starts_at'],
                $data['planned_ends_at'],
                isset($data['extended_from_contract_id']) ? (int) $data['extended_from_contract_id'] : null,
            );
            $this->assertVehicleLicenseCoversRental(
                (int) $vehicle->id,
                Carbon::parse($data['planned_ends_at'])->toDateString(),
            );

            $number = $data['number'] ?? $this->nextNumber();

            // الأسعار تُقترح من السيارة؛ التعديل عند الإنشاء يتطلب صلاحية تجاوز السعر.
            $dailyRate = $allowPriceOverride && isset($data['daily_rate'])
                ? (int) $data['daily_rate']
                : (int) $vehicle->daily_rate;
            $deposit = $allowPriceOverride && isset($data['deposit'])
                ? (int) $data['deposit']
                : (int) $vehicle->default_deposit;
            $ops = TenantSetting::query()->first()?->operational ?? [];
            $kmAllowance = $allowPriceOverride && isset($data['daily_km_allowance'])
                ? (int) $data['daily_km_allowance']
                : (int) ($ops['default_daily_km_allowance'] ?? self::DEFAULT_DAILY_KM_ALLOWANCE);
            $excessRate = $allowPriceOverride && isset($data['excess_km_rate'])
                ? (int) $data['excess_km_rate']
                : (int) ($ops['default_excess_km_rate'] ?? self::DEFAULT_EXCESS_KM_RATE);

            $accessories = $this->resolveAccessoriesSnapshot($vehicle, $data['accessory_ids'] ?? null);
            $delivery = $this->pricing->normalizeDeliveryService($data);

            if (array_key_exists('odometer', $data) && $data['odometer'] !== null) {
                $odo = (int) $data['odometer'];
                if ($odo < (int) $vehicle->odometer) {
                    throw new DomainException('العداد الحالي أقل من عداد العربية المسجّل.');
                }
                $vehicle->update(['odometer' => $odo, 'odometer_source' => 'manual']);
            }

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
                'has_delivery_service' => $delivery['has_delivery_service'],
                'delivery_service_fee' => $delivery['delivery_service_fee'],
                'delivery_service_notes' => $delivery['delivery_service_notes'],
                'sales_user_id' => $userId,
                'notes' => $data['notes'] ?? null,
                'balance_due_on' => $data['balance_due_on'] ?? null,
                'accessories_json' => $accessories,
                'extended_from_contract_id' => $data['extended_from_contract_id'] ?? null,
                'swapped_from_contract_id' => $data['swapped_from_contract_id'] ?? null,
                'swap_reason' => $data['swap_reason'] ?? null,
            ]);

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'created',
                'record_type' => Contract::class,
                'record_id' => $contract->id,
                'after' => [
                    'number' => $contract->number,
                    'vehicle_id' => $contract->vehicle_id,
                    'plate' => $vehicle->plate,
                    'customer_id' => $contract->customer_id,
                ],
            ]);

            $paymentAmount = (int) ($data['payment_amount'] ?? 0);
            if ($paymentAmount > 0) {
                $this->accounting->recordPayment([
                    'amount' => $paymentAmount,
                    'method' => $data['payment_method'] ?? 'cash',
                    'direction' => 'in',
                    'reference' => $data['payment_reference'] ?? ('NEW-'.$contract->number),
                    'link_type' => 'contract',
                    'link_id' => $contract->id,
                    'notes' => $data['payment_notes'] ?? 'دفعة عند إنشاء الحجز',
                    'balance_due_on' => $data['balance_due_on'] ?? null,
                ], $userId);

                AuditLog::query()->create([
                    'user_id' => $userId,
                    'action' => 'payment_recorded',
                    'record_type' => Contract::class,
                    'record_id' => $contract->id,
                    'after' => [
                        'number' => $contract->number,
                        'amount' => $paymentAmount,
                        'method' => $data['payment_method'] ?? 'cash',
                        'balance_due_on' => $data['balance_due_on'] ?? null,
                    ],
                ]);
            } else {
                $this->syncBalanceDueOn($contract, $data['balance_due_on'] ?? null);
            }

            return $contract->fresh()->load(['customer', 'vehicle', 'salesUser:id,name']);
        });
    }

    /**
     * يضبط أو يمسح تاريخ تحصيل المتبقي حسب الرصيد الحالي.
     */
    public function syncBalanceDueOn(Contract $contract, mixed $dueOn = null): void
    {
        $remaining = app(ContractLedgerMath::class)->remainingCollectible($contract->fresh());
        if ($remaining < 1) {
            if ($contract->balance_due_on !== null) {
                $contract->balance_due_on = null;
                $contract->save();
            }

            return;
        }

        if ($dueOn === null || $dueOn === '') {
            return;
        }

        $contract->balance_due_on = Carbon::parse((string) $dueOn)->toDateString();
        $contract->save();
    }

    /**
     * @param  list<int|string>|null  $accessoryIds
     * @return list<array{id:int,name:string,qty:int}>
     */
    private function resolveAccessoriesSnapshot(Vehicle $vehicle, ?array $accessoryIds): array
    {
        $vehicle->loadMissing('accessories');
        $all = $vehicle->accessories->where('is_active', true);
        if ($accessoryIds === null) {
            return $all->map(fn ($a) => [
                'id' => (int) $a->id,
                'name' => (string) $a->name,
                'qty' => (int) ($a->default_qty ?? 1),
            ])->values()->all();
        }
        $wanted = collect($accessoryIds)->map(fn ($id) => (int) $id)->filter()->all();

        return $all->whereIn('id', $wanted)->map(fn ($a) => [
            'id' => (int) $a->id,
            'name' => (string) $a->name,
            'qty' => (int) ($a->default_qty ?? 1),
        ])->values()->all();
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

            $this->assertOwnerAgreementCoversRental($vehicle, $locked->planned_ends_at);
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
     * تعديل عقد قيد المراجعة فقط — بعد الاعتماد تُثبَّت الحقول والأسعار.
     *
     * @param  array<string, mixed>  $data
     */
    public function updatePending(Contract $contract, array $data, ?int $userId = null, bool $allowPriceOverride = false): Contract
    {
        if ($contract->status !== 'pending') {
            throw new DomainException('يمكن تعديل بيانات العقد فقط وهو قيد المراجعة — بعد الاعتماد تُثبَّت الأسعار والحقول.');
        }

        return DB::transaction(function () use ($contract, $data, $userId, $allowPriceOverride) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new DomainException('يمكن تعديل بيانات العقد فقط وهو قيد المراجعة — بعد الاعتماد تُثبَّت الأسعار والحقول.');
            }

            $vehicleId = (int) ($data['vehicle_id'] ?? $locked->vehicle_id);
            $startsAt = (string) ($data['starts_at'] ?? $locked->starts_at);
            $endsAt = (string) ($data['planned_ends_at'] ?? $locked->planned_ends_at);

            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->whereKey($vehicleId)->lockForUpdate()->firstOrFail();
            if (! $vehicle->is_active) {
                throw new DomainException('العربية غير نشطة.');
            }
            if (in_array($vehicle->manual_status, [
                VehicleAvailabilityService::STATUS_MAINTENANCE,
                VehicleAvailabilityService::STATUS_OUT_OF_SERVICE,
                VehicleAvailabilityService::STATUS_SOLD,
            ], true)) {
                throw new DomainException('العربية غير متاحة للعقد في حالتها الحالية.');
            }

            $customerId = (int) ($data['customer_id'] ?? $locked->customer_id);
            $customer = Customer::query()->findOrFail($customerId);
            if (in_array($customer->status, ['blacklist', 'blacklisted'], true)) {
                throw new DomainException('العميل في القائمة السوداء.');
            }

            $this->assertOwnerAgreementCoversRental($vehicle, $endsAt);
            $this->assertNoOverlap($vehicleId, $startsAt, $endsAt, $locked->id);
            $this->assertVehicleLicenseCoversRental(
                (int) $vehicle->id,
                Carbon::parse($endsAt)->toDateString(),
            );

            $dailyRate = $allowPriceOverride && array_key_exists('daily_rate', $data) && $data['daily_rate'] !== null
                ? (int) $data['daily_rate']
                : (array_key_exists('daily_rate', $data) && $data['daily_rate'] !== null
                    ? (int) $data['daily_rate']
                    : (int) $locked->frozen_daily_rate);
            if (! $allowPriceOverride && array_key_exists('daily_rate', $data) && $data['daily_rate'] !== null
                && (int) $data['daily_rate'] !== (int) $vehicle->daily_rate
                && (int) $data['daily_rate'] !== (int) $locked->frozen_daily_rate) {
                // السماح بتعديل السعر عند قيد المراجعة إن أُرسل — نفس مسار الإنشاء مع صلاحية التجاوز من المتحكم.
                $dailyRate = (int) $data['daily_rate'];
            }

            $deposit = array_key_exists('deposit', $data) && $data['deposit'] !== null
                ? (int) $data['deposit']
                : (int) $locked->frozen_agreed_deposit;
            $kmAllowance = array_key_exists('daily_km_allowance', $data) && $data['daily_km_allowance'] !== null
                ? (int) $data['daily_km_allowance']
                : (int) ($locked->frozen_daily_km_allowance ?? 200);
            $excessRate = array_key_exists('excess_km_rate', $data) && $data['excess_km_rate'] !== null
                ? (int) $data['excess_km_rate']
                : (int) ($locked->frozen_excess_km_rate ?? 5);

            if (array_key_exists('accessory_ids', $data)) {
                $accessories = $this->resolveAccessoriesSnapshot($vehicle, $data['accessory_ids']);
            } else {
                $accessories = is_array($locked->accessories_json) ? $locked->accessories_json : [];
            }

            if (array_key_exists('odometer', $data) && $data['odometer'] !== null) {
                $odo = (int) $data['odometer'];
                if ($odo < (int) $vehicle->odometer && $vehicleId === (int) $locked->vehicle_id) {
                    // السماح بنفس العداد أو أعلى؛ إن تغيّرت العربية استخدم عدادها الحالي كحد أدنى.
                }
                if ($odo < (int) $vehicle->odometer) {
                    throw new DomainException('العداد الحالي أقل من عداد العربية المسجّل.');
                }
                $vehicle->update(['odometer' => $odo, 'odometer_source' => 'manual']);
            }

            $before = [
                'customer_id' => $locked->customer_id,
                'vehicle_id' => $locked->vehicle_id,
                'starts_at' => (string) $locked->starts_at,
                'planned_ends_at' => (string) $locked->planned_ends_at,
                'frozen_daily_rate' => $locked->frozen_daily_rate,
                'frozen_agreed_deposit' => $locked->frozen_agreed_deposit,
            ];

            $locked->fill([
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'guarantor_customer_id' => array_key_exists('guarantor_customer_id', $data)
                    ? $data['guarantor_customer_id']
                    : $locked->guarantor_customer_id,
                'starts_at' => $startsAt,
                'planned_ends_at' => $endsAt,
                'min_days' => array_key_exists('min_days', $data) ? $data['min_days'] : $locked->min_days,
                'frozen_daily_rate' => $dailyRate,
                'frozen_daily_km_allowance' => $kmAllowance,
                'frozen_excess_km_rate' => $excessRate,
                'frozen_agreed_deposit' => $deposit,
                'discount' => array_key_exists('discount', $data) ? (int) $data['discount'] : $locked->discount,
                'other_fees' => array_key_exists('other_fees', $data) ? (int) $data['other_fees'] : $locked->other_fees,
                'other_fees_description' => array_key_exists('other_fees_description', $data)
                    ? $data['other_fees_description']
                    : $locked->other_fees_description,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $locked->notes,
                'accessories_json' => $accessories,
            ]);
            if (
                array_key_exists('has_delivery_service', $data)
                || array_key_exists('delivery_service_fee', $data)
                || array_key_exists('delivery_service_notes', $data)
            ) {
                $delivery = $this->pricing->normalizeDeliveryService(
                    $data,
                    (bool) $locked->has_delivery_service,
                    (int) ($locked->delivery_service_fee ?? 0),
                    $locked->delivery_service_notes,
                );
                $locked->fill($delivery);
            }
            $locked->save();

            if (array_key_exists('balance_due_on', $data)) {
                $this->syncBalanceDueOn($locked, $data['balance_due_on']);
            }

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'updated',
                'record_type' => Contract::class,
                'record_id' => $locked->id,
                'before' => $before,
                'after' => [
                    'customer_id' => $locked->customer_id,
                    'vehicle_id' => $locked->vehicle_id,
                    'starts_at' => (string) $locked->starts_at,
                    'planned_ends_at' => (string) $locked->planned_ends_at,
                    'frozen_daily_rate' => $locked->frozen_daily_rate,
                    'frozen_agreed_deposit' => $locked->frozen_agreed_deposit,
                ],
            ]);

            return $locked->fresh()->load(['customer', 'vehicle', 'salesUser:id,name', 'deposit']);
        });
    }

    /**
     * تعديل أسعار بعد الاعتماد — موقوف.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateFrozenPricing(Contract $contract, array $data, ?int $userId = null): Contract
    {
        if ($contract->status === 'pending') {
            return $this->updatePending($contract, $data, $userId, true);
        }

        throw new DomainException('لا يمكن تعديل أسعار العقد بعد الاعتماد.');
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
            $this->assertOwnerAgreementCoversRental($vehicle, $locked->planned_ends_at);

            $otherActive = Contract::query()
                ->where('vehicle_id', $locked->vehicle_id)
                ->where('status', 'active')
                ->where('id', '!=', $locked->id)
                ->lockForUpdate()
                ->exists();
            if ($otherActive) {
                throw new DomainException('لا يمكن التسليم: توجد حيازة نشطة أخرى على نفس العربية.');
            }

            $this->assertNoOverlap(
                (int) $locked->vehicle_id,
                $locked->starts_at,
                $locked->planned_ends_at,
                $locked->id,
            );

            if ($odometerOut < (int) $vehicle->odometer) {
                throw new DomainException('عداد الخروج أقل من عداد العربية المسجّل.');
            }

            $accessories = $inspection['accessories'] ?? [];
            if ($accessories === [] && is_array($locked->accessories_json)) {
                $accessories = collect($locked->accessories_json)->map(fn ($a) => [
                    'name' => $a['name'] ?? 'مستلزم',
                    'expected_qty' => (int) ($a['qty'] ?? 1),
                    'actual_qty' => (int) ($a['qty'] ?? 1),
                    'ok' => true,
                    'accessory_id' => $a['id'] ?? null,
                ])->all();
            }

            $handoverAt = now();
            if ($locked->starts_at && $handoverAt->lt($locked->starts_at)) {
                $locked->starts_at = $handoverAt;
            }

            $this->inspections->record($locked->id, [
                'type' => 'handover',
                'odometer' => $odometerOut,
                'notes' => $inspection['notes'] ?? null,
                'signature_data' => $inspection['signature_data'] ?? null,
                'accessories' => $accessories,
                'damages' => $inspection['damages'] ?? [],
            ], $userId);

            $locked->fill([
                'status' => 'active',
                'odometer_out' => $odometerOut,
            ])->save();

            $vehicle->update(['odometer' => $odometerOut]);

            $paymentAmount = (int) ($inspection['payment_amount'] ?? 0);
            if ($paymentAmount > 0) {
                $this->accounting->recordPayment([
                    'amount' => $paymentAmount,
                    'method' => $inspection['payment_method'] ?? 'cash',
                    'direction' => 'in',
                    'reference' => $inspection['payment_reference'] ?? ('HO-'.$locked->number),
                    'link_type' => 'contract',
                    'link_id' => $locked->id,
                    'notes' => 'دفعة عند التسليم'.($paymentAmount > 0 ? ' (جزئية أو كاملة)' : ''),
                    'balance_due_on' => $inspection['balance_due_on'] ?? null,
                ], $userId);

                AuditLog::query()->create([
                    'user_id' => $userId,
                    'action' => 'payment_recorded',
                    'record_type' => Contract::class,
                    'record_id' => $locked->id,
                    'after' => [
                        'number' => $locked->number,
                        'amount' => $paymentAmount,
                        'method' => $inspection['payment_method'] ?? 'cash',
                    ],
                ]);
            }

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'handover',
                'record_type' => Contract::class,
                'record_id' => $locked->id,
                'after' => [
                    'number' => $locked->number,
                    'status' => 'active',
                    'odometer_out' => $odometerOut,
                    'plate' => $vehicle->plate,
                ],
            ]);

            $this->notifyLifecycle(
                'تم تسليم عربية',
                "العقد {$locked->number} أصبح نشطاً بعد التسليم.",
                $locked,
                $userId,
            );

            return $locked->fresh(['customer', 'vehicle', 'deposit', 'inspections.damages', 'salesUser:id,name']);
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
                $lateDays = $this->pricing->countLateDays($plannedEnd, $end, $threshold);
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
                'delivery_service_fee' => (int) ($locked->delivery_service_fee ?? 0),
            ]);
            $finalInt = (int) round($final);
            if ($finalInt < 0) {
                throw new DomainException('الخصم أكبر من إجمالي العقد.');
            }

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

            Contract::query()
                ->where('extended_from_contract_id', $locked->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'cancel_reason' => 'أُلغي لارتباطه باستلام العقد '.$locked->number,
                ]);

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
                'تم استلام عربية',
                "العقد {$locked->number} أُقفل. الإجمالي: {$locked->final_total} ج.م.",
                $locked,
                $userId,
            );

            return $locked->fresh(['customer', 'vehicle', 'deposit', 'inspections.damages', 'vehicleSwaps']);
        });
    }

    public function cancel(Contract $contract, string $reason, ?int $userId = null): Contract
    {
        if ($contract->status === 'cancelled') {
            throw new DomainException('العقد ملغي مسبقاً.');
        }
        if (trim($reason) === '') {
            throw new DomainException('سبب الإلغاء إلزامي.');
        }

        return DB::transaction(function () use ($contract, $reason, $userId) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'cancelled') {
                throw new DomainException('العقد ملغي مسبقاً.');
            }
            if (! in_array($locked->status, ['pending', 'approved'], true)) {
                throw new DomainException('بعد التسليم يُستخدم إنهاء مبكر أو استلام — لا إلغاء.');
            }

            $locked->fill([
                'status' => 'cancelled',
                'cancel_reason' => $reason,
            ])->save();
            $contract = $locked;

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
                    $refundRef = 'REFUND-'.$payment->id;
                    $already = Payment::query()
                        ->where('reference', $refundRef)
                        ->where('direction', 'out')
                        ->exists();
                    if ($already) {
                        return;
                    }
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
        if ($source->status === 'completed') {
            throw new DomainException('العقد مُقفل — لا يمكن التمديد.');
        }
        if (! in_array($source->status, ['approved', 'active'], true)) {
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
            'has_delivery_service' => (bool) $source->has_delivery_service,
            'delivery_service_fee' => (int) ($source->delivery_service_fee ?? 0),
            'delivery_service_notes' => $source->delivery_service_notes,
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
            throw new DomainException('العربية البديلة يجب أن تختلف عن العربية الحالية.');
        }

        $odometerIn = (int) $data['odometer_in'];
        $odometerOut = (int) $data['odometer_out'];
        $segmentStart = $this->currentSegmentStartOdometer($source);
        if ($odometerIn < $segmentStart) {
            throw new DomainException('عداد إرجاع العربية القديمة أقل من عداد بداية الحيازة.');
        }

        return DB::transaction(function () use ($source, $data, $userId, $replacementId, $odometerIn, $odometerOut, $segmentStart) {
            $locked = Contract::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw new DomainException('التبديل بسبب عطل يتطلب عقداً نشطاً.');
            }

            $replacement = Vehicle::query()->whereKey($replacementId)->lockForUpdate()->firstOrFail();

            $this->assertNoOverlap(
                $replacementId,
                now(),
                $locked->planned_ends_at,
                $locked->id,
            );

            $this->assertOwnerAgreementCoversRental($replacement, $locked->planned_ends_at);
            $this->assertVehicleLicenseCoversRental(
                (int) $replacement->id,
                $locked->planned_ends_at->toDateString(),
            );
            if (! $replacement->is_active) {
                throw new DomainException('العربية البديلة غير نشطة.');
            }
            if (in_array($replacement->manual_status, [
                VehicleAvailabilityService::STATUS_MAINTENANCE,
                VehicleAvailabilityService::STATUS_OUT_OF_SERVICE,
                VehicleAvailabilityService::STATUS_SOLD,
            ], true)) {
                throw new DomainException('العربية البديلة غير متاحة.');
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
                'notes' => trim(($locked->notes ? $locked->notes."\n" : '').'تم تبديل عربية بسبب عطل'),
            ])->save();

            Vehicle::query()->whereKey($replacementId)->update(['odometer' => $odometerOut]);

            // Same-contract swap: unique (contract_id, type) allows one handover — update it
            // for the replacement vehicle instead of inserting a second handover row.
            $handover = Inspection::query()
                ->where('contract_id', $locked->id)
                ->where('type', 'handover')
                ->first();
            if ($handover) {
                $handover->fill([
                    'odometer' => $odometerOut,
                    'notes' => trim(($handover->notes ? $handover->notes."\n" : '').'تسليم البديلة عند التبديل بسبب عطل'),
                    'signature_data' => $data['signature_data'] ?? $handover->signature_data,
                    'accessories_json' => $data['accessories'] ?? $handover->accessories_json ?? [],
                    'inspected_at' => now(),
                    'inspector_id' => $userId ?? $handover->inspector_id,
                ])->save();
            } else {
                $this->inspections->record($locked->id, [
                    'type' => 'handover',
                    'odometer' => $odometerOut,
                    'notes' => 'تسليم البديلة عند التبديل بسبب عطل',
                    'signature_data' => $data['signature_data'] ?? null,
                    'accessories' => $data['accessories'] ?? [],
                    'damages' => $data['damages'] ?? [],
                ], $userId);
            }

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'swapped',
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

            $agreed = (int) $contract->frozen_agreed_deposit;
            $amount = (int) ($data['amount'] ?? $agreed);
            if ($amount <= 0) {
                throw new DomainException('مبلغ التأمين النقدي غير صالح.');
            }
            if ($agreed > 0 && $amount !== $agreed) {
                throw new DomainException('مبلغ التأمين يجب أن يساوي التأمين المتفق ('.$agreed.').');
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
            $this->accounting->postDepositHold($deposit->fresh(), $contract, $userId, (string) $data['method']);

            return $deposit;
        });
    }

    /**
     * @param  array{reason: string, amount: int}  $data
     */
    public function deductDeposit(Deposit $deposit, array $data, int $userId): Deposit
    {
        return DB::transaction(function () use ($deposit, $data, $userId) {
            /** @var Deposit $deposit */
            $deposit = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if (! in_array($deposit->status, ['received', 'partial'], true)) {
                throw new DomainException('لا يمكن الخصم من هذا التأمين.');
            }
            $amount = (int) $data['amount'];
            $held = $deposit->remaining();
            if ($amount <= 0 || $amount > $held) {
                throw new DomainException('الخصم أكبر من التأمين المحتجز ('.$held.' ج.م).');
            }

            return $this->writeDepositDeduction($deposit, $data, $amount, $userId);
        });
    }

    /**
     * @param  array{reason: string, amount: int}  $data
     */
    private function writeDepositDeduction(Deposit $deposit, array $data, int $amount, int $userId): Deposit
    {
        $deduction = DepositDeduction::query()->create([
            'deposit_id' => $deposit->id,
            'reason' => $data['reason'],
            'amount' => $amount,
            'user_id' => $userId,
            'deducted_at' => now(),
        ]);

        $deposit->deductions_total = (int) $deposit->deductions_total + $amount;
        $deposit->status = $deposit->remaining() === 0 ? 'settled' : 'partial';
        $deposit->save();

        $contract = Contract::query()->find($deposit->contract_id);
        if ($contract) {
            Payment::query()->create([
                'amount' => $amount,
                'paid_at' => now(),
                'method' => 'deposit',
                'direction' => 'in',
                'reference' => 'DEP-DED-'.$deduction->id,
                'link_type' => 'contract',
                'link_id' => $contract->id,
                'user_id' => $userId,
                'notes' => 'تسوية تأمين على العقد '.$contract->number,
            ]);
            $this->accounting->postDepositApply($deposit, $contract, $amount, (int) $deduction->id, $userId);
        }

        return $deposit->fresh('deductions');
    }

    public function returnDeposit(Deposit $deposit, string $method, string $receiptNumber, ?int $userId = null): Deposit
    {
        return DB::transaction(function () use ($deposit, $method, $receiptNumber, $userId) {
            /** @var Deposit $deposit */
            $deposit = Deposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            return $this->writeDepositReturn($deposit, $method, $receiptNumber, $userId);
        });
    }

    private function writeDepositReturn(Deposit $deposit, string $method, string $receiptNumber, ?int $userId): Deposit
    {
        if (! in_array($deposit->status, ['received', 'partial'], true)) {
            throw new DomainException('لا يمكن رد هذا التأمين.');
        }

        $contract = Contract::query()->find($deposit->contract_id);
        if ($contract && in_array($contract->status, ['active', 'completed'], true)) {
            $balance = $this->ledgerMath->forContract($contract)['balance'];
            $violationsDue = (int) Violation::query()
                ->where('contract_id', $contract->id)
                ->whereNotIn('status', ['paid', 'confirmed'])
                ->sum('amount');
            if ($balance > 0 || $violationsDue > 0) {
                throw new DomainException('لا يمكن رد التأمين قبل تسوية متبقي العقد أو المخالفات.');
            }
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
            $this->accounting->postDepositReturn($deposit, $remaining, $userId, $method);
        }

        return $deposit->fresh('deductions');
    }

    private function assertNoOverlap(int $vehicleId, mixed $startsAt, mixed $endsAt, ?int $ignoreId = null): void
    {
        $q = Contract::query()->where('vehicle_id', $vehicleId);
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }

        $q->where(function ($outer) use ($startsAt, $endsAt) {
            $outer->where(function ($window) use ($startsAt, $endsAt) {
                $window->whereIn('status', ['pending', 'approved'])
                    ->where('starts_at', '<', $endsAt)
                    ->where('planned_ends_at', '>', $startsAt);
            })->orWhere(function ($active) use ($endsAt) {
                // A car that is still out stays occupied until return, even past the planned end.
                $active->where('status', 'active')
                    ->where('starts_at', '<', $endsAt);
            });
        });

        $conflict = $q->first(['id', 'number']);
        if ($conflict) {
            throw new DomainException('العربية محجوزة بعقد متعارض: '.$conflict->number);
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
            $this->assertVehicleLicenseCoversRental(
                (int) $contract->vehicle_id,
                $contract->planned_ends_at->toDateString(),
            );
            $expired = VehicleDocument::query()
                ->where('vehicle_id', $contract->vehicle_id)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<', now()->toDateString())
                ->first();
            if ($expired) {
                throw new DomainException('مستند عربية منتهٍ ('.$expired->type.') — يُمنع الاعتماد.');
            }

            $endsBeforeContract = VehicleDocument::query()
                ->where('vehicle_id', $contract->vehicle_id)
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<', $contract->planned_ends_at->toDateString())
                ->first();
            if ($endsBeforeContract) {
                throw new DomainException('مستند العربية ('.$endsBeforeContract->type.') ينتهي قبل نهاية العقد المخططة.');
            }
        }
    }

    private function assertOwnerAgreementCoversRental(Vehicle $vehicle, mixed $plannedEndsAt): void
    {
        $ends = $vehicle->owner_agreement_ends_on?->toDateString();
        if ($ends === null || $ends === '') {
            return;
        }

        $planned = Carbon::parse($plannedEndsAt)
            ->timezone(config('app.timezone', 'Africa/Cairo'))
            ->toDateString();
        $today = now()->timezone(config('app.timezone', 'Africa/Cairo'))->toDateString();
        if ($planned > $ends || $today > $ends) {
            throw new DomainException("عقد المالك لهذه العربية ينتهي في {$ends} — لا يمكن تأجيرها بعد هذا التاريخ");
        }
    }

    private function assertVehicleLicenseCoversRental(int $vehicleId, string $plannedEndDate): void
    {
        $settings = TenantSetting::query()->first();
        $block = (bool) (($settings?->operational ?? [])['block_expired_vehicle_docs'] ?? true);
        if (! $block) {
            return;
        }

        $doc = VehicleDocument::query()
            ->where('vehicle_id', $vehicleId)
            ->where(function ($q) {
                $q->where('type', 'رخصة تسيير')
                    ->orWhere('type', 'like', '%رخص%')
                    ->orWhere('type', 'like', '%license%');
            })
            ->whereNotNull('expires_on')
            ->orderByDesc('id')
            ->first();
        if (! $doc) {
            return;
        }
        $expires = $doc->expires_on?->toDateString();
        if ($expires && $expires < $plannedEndDate) {
            throw new DomainException('رخصة العربية تنتهي قبل نهاية العقد المخططة.');
        }
    }

    private function nextNumber(): string
    {
        if (! Schema::hasTable('contract_number_sequences')) {
            $last = Contract::query()->withTrashed()->orderByDesc('id')->lockForUpdate()->value('number');
            $seq = 1;
            if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
                $seq = ((int) $m[1]) + 1;
            }

            return $this->formatContractNumber($this->firstFreeContractSequence($seq));
        }

        $row = DB::table('contract_number_sequences')->where('id', 1)->lockForUpdate()->first();
        $seq = $row ? (int) $row->next_value : 1;
        if ($seq < 1) {
            $seq = 1;
        }

        $highest = (int) (Contract::query()->withTrashed()
            ->pluck('number')
            ->map(function ($number) {
                return is_string($number) && preg_match('/^CNT-(\d+)$/', $number, $m) ? (int) $m[1] : 0;
            })
            ->max() ?? 0);
        if ($highest >= $seq) {
            $seq = $highest + 1;
        }
        $seq = $this->firstFreeContractSequence($seq);

        $next = $seq + 1;
        if ($row) {
            DB::table('contract_number_sequences')->where('id', 1)->update([
                'next_value' => $next,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('contract_number_sequences')->insert([
                'id' => 1,
                'next_value' => $next,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $this->formatContractNumber($seq);
    }

    private function firstFreeContractSequence(int $seq): int
    {
        while (Contract::query()->withTrashed()->where('number', $this->formatContractNumber($seq))->exists()) {
            $seq++;
        }

        return $seq;
    }

    private function formatContractNumber(int $seq): string
    {
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
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
