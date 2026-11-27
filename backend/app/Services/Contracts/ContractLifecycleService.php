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
        // $allowPriceOverride محفوظ للتوافق؛ سعر/وديعة الإنشاء من النموذج دائماً (ن٫٥).
        unset($allowPriceOverride);

        return DB::transaction(function () use ($data, $userId) {
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
            $number = $data['number'] ?? $this->nextNumber();

            // ن٫٥: السعر اليومي والوديعة من النموذج عند الإنشاء بلا اشتراط صلاحية تجاوز السعر.
            // صلاحية contracts_price.override محصورة بتعديل أسعار عقد معتمد لاحقاً.
            $dailyRate = array_key_exists('daily_rate', $data) && $data['daily_rate'] !== null
                ? (int) $data['daily_rate']
                : (int) $vehicle->daily_rate;
            $deposit = array_key_exists('deposit', $data) && $data['deposit'] !== null
                ? (int) $data['deposit']
                : (int) $vehicle->default_deposit;
            $depositCurrency = \App\Support\DepositCurrency::normalize(
                array_key_exists('deposit_currency', $data) ? ($data['deposit_currency'] ?? null) : null
            );
            $ops = TenantSetting::query()->first()?->operational ?? [];
            $halfFactor = is_numeric($ops['showroom_half_monthly_factor'] ?? null)
                ? (float) $ops['showroom_half_monthly_factor']
                : ContractPricingService::DEFAULT_HALF_MONTHLY_FACTOR;
            $monthlyFactor = is_numeric($ops['showroom_monthly_factor'] ?? null)
                ? (float) $ops['showroom_monthly_factor']
                : ContractPricingService::DEFAULT_MONTHLY_FACTOR;
            $halfMonthlyRate = array_key_exists('half_monthly_rate', $data) && $data['half_monthly_rate'] !== null
                ? (int) $data['half_monthly_rate']
                : (array_key_exists('weekly_rate', $data) && $data['weekly_rate'] !== null
                    ? (int) $data['weekly_rate'] // توافق مؤقت إن وصل الطلب بالاسم القديم
                    : ($vehicle->half_monthly_rate !== null
                        ? (int) $vehicle->half_monthly_rate
                        : (int) round($dailyRate * $halfFactor)));
            $monthlyRate = array_key_exists('monthly_rate', $data) && $data['monthly_rate'] !== null
                ? (int) $data['monthly_rate']
                : ($vehicle->monthly_rate !== null
                    ? (int) $vehicle->monthly_rate
                    : (int) round($dailyRate * $monthlyFactor));
            $plannedDays = $this->pricing->countRentalDays(
                Carbon::parse($data['starts_at']),
                Carbon::parse($data['planned_ends_at']),
                $this->fractionDayThreshold(),
            );
            $rentBasis = $this->pricing->resolveRentBasisFromDays($plannedDays);
            // بدل الكم وسعر الزيادة يُحدَّدان عند الإنشاء لأي منشئ (بلا صلاحية تجاوز السعر).
            // مفاتيح الإعدادات: default_daily_km / excess_km_rate (الواجهة) مع توافق الأسماء القديمة.
            $defaultKmAllowance = (int) (
                $ops['default_daily_km_allowance']
                ?? $ops['default_daily_km']
                ?? self::DEFAULT_DAILY_KM_ALLOWANCE
            );
            $defaultExcessRate = (int) (
                $ops['default_excess_km_rate']
                ?? $ops['excess_km_rate']
                ?? self::DEFAULT_EXCESS_KM_RATE
            );
            $kmAllowance = array_key_exists('daily_km_allowance', $data) && $data['daily_km_allowance'] !== null
                ? (int) $data['daily_km_allowance']
                : $defaultKmAllowance;
            $excessRate = array_key_exists('excess_km_rate', $data) && $data['excess_km_rate'] !== null
                ? (int) $data['excess_km_rate']
                : $defaultExcessRate;
            $prepaidExcessKm = max(0, (int) ($data['prepaid_excess_km'] ?? 0));
            $prepaidExcessAmount = $this->pricing->prepaidExcessAmount($prepaidExcessKm, $excessRate);

            $accessories = $this->resolveAccessoriesSnapshot($vehicle, $data['accessory_ids'] ?? null);
            $delivery = $this->pricing->normalizeDeliveryService($data);
            [$includeVehicleValue, $vehicleValueText] = $this->normalizeVehicleValueObligation($data);

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
                'rent_basis' => $rentBasis,
                'frozen_half_monthly_rate' => $halfMonthlyRate,
                'frozen_monthly_rate' => $monthlyRate,
                'frozen_daily_km_allowance' => $kmAllowance,
                'frozen_excess_km_rate' => $excessRate,
                'prepaid_excess_km' => $prepaidExcessKm,
                'prepaid_excess_km_amount' => $prepaidExcessAmount,
                'frozen_agreed_deposit' => $deposit,
                'frozen_deposit_currency' => $depositCurrency,
                'frozen_operating_insurance_fee' => $data['operating_insurance_fee'] ?? null,
                'include_vehicle_value_obligation' => $includeVehicleValue,
                'frozen_vehicle_value_text' => $vehicleValueText,
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

    public function approve(Contract $contract, ?int $userId = null, bool $reSnapshotRatesFromVehicle = false): Contract
    {
        // $reSnapshotRatesFromVehicle مُهمَل عمداً: الأسعار المجمّدة لا تُستبدل بسعر العربية أبداً.
        unset($reSnapshotRatesFromVehicle);

        return DB::transaction(function () use ($contract, $userId) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new DomainException('لا يمكن منح موافقة الإدارة على هذا العقد في حالته الحالية.');
            }

            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->whereKey($locked->vehicle_id)->lockForUpdate()->firstOrFail();

            $this->assertOwnerAgreementCoversRental($vehicle, $locked->planned_ends_at);
            $this->assertNoOverlap(
                (int) $locked->vehicle_id,
                $locked->starts_at,
                $locked->planned_ends_at,
                $locked->id,
                $locked->extended_from_contract_id !== null ? (int) $locked->extended_from_contract_id : null,
            );

            $days = $this->pricing->countRentalDays(
                $locked->starts_at,
                $locked->planned_ends_at,
                $this->fractionDayThreshold(),
            );

            // ن٫٥ / RSD ٣٫٤: الأسعار المجمّدة عند الإنشاء تبقى بلا استثناء — لا تُستبدل بسعر العربية الحيّ.
            $rate = (int) $locked->frozen_daily_rate;
            $deposit = (int) $locked->frozen_agreed_deposit;
            if ($rate < 1) {
                throw new DomainException('لا يمكن منح موافقة الإدارة بدون سعر يومي مثبّت على العقد.');
            }

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
                'موافقة الإدارة على عقد',
                "العقد {$locked->number} بانتظار التسليم بعد موافقة الإدارة.",
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
            throw new DomainException('يمكن تعديل بيانات العقد فقط وهو قيد المراجعة — بعد موافقة الإدارة تُثبَّت الأسعار والحقول.');
        }

        // $allowPriceOverride للتوافق؛ تعديل قيد المراجعة يقبل السعر المكتوب دائماً.
        unset($allowPriceOverride);

        return DB::transaction(function () use ($contract, $data, $userId) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new DomainException('يمكن تعديل بيانات العقد فقط وهو قيد المراجعة — بعد موافقة الإدارة تُثبَّت الأسعار والحقول.');
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

            // قيد المراجعة: أي سعر مُرسل من النموذج يُثبَّت على العقد (نفس قاعدة الإنشاء).
            $dailyRate = array_key_exists('daily_rate', $data) && $data['daily_rate'] !== null
                ? (int) $data['daily_rate']
                : (int) $locked->frozen_daily_rate;
            $halfMonthlyRate = array_key_exists('half_monthly_rate', $data) && $data['half_monthly_rate'] !== null
                ? (int) $data['half_monthly_rate']
                : (array_key_exists('weekly_rate', $data) && $data['weekly_rate'] !== null
                    ? (int) $data['weekly_rate']
                    : (int) ($locked->frozen_half_monthly_rate ?? 0));
            $monthlyRate = array_key_exists('monthly_rate', $data) && $data['monthly_rate'] !== null
                ? (int) $data['monthly_rate']
                : (int) ($locked->frozen_monthly_rate ?? 0);
            $rentBasis = $this->pricing->resolveRentBasisFromDays(
                $this->pricing->countRentalDays(
                    Carbon::parse($startsAt),
                    Carbon::parse($endsAt),
                    $this->fractionDayThreshold(),
                ),
            );

            $deposit = array_key_exists('deposit', $data) && $data['deposit'] !== null
                ? (int) $data['deposit']
                : (int) $locked->frozen_agreed_deposit;
            $depositCurrency = array_key_exists('deposit_currency', $data)
                ? \App\Support\DepositCurrency::normalize($data['deposit_currency'] ?? null)
                : \App\Support\DepositCurrency::normalize($locked->frozen_deposit_currency ?? null);
            $kmAllowance = array_key_exists('daily_km_allowance', $data) && $data['daily_km_allowance'] !== null
                ? (int) $data['daily_km_allowance']
                : (int) ($locked->frozen_daily_km_allowance ?? 200);
            $excessRate = array_key_exists('excess_km_rate', $data) && $data['excess_km_rate'] !== null
                ? (int) $data['excess_km_rate']
                : (int) ($locked->frozen_excess_km_rate ?? 5);
            $prepaidExcessKm = array_key_exists('prepaid_excess_km', $data) && $data['prepaid_excess_km'] !== null
                ? max(0, (int) $data['prepaid_excess_km'])
                : (int) ($locked->prepaid_excess_km ?? 0);
            $prepaidExcessAmount = $this->pricing->prepaidExcessAmount($prepaidExcessKm, $excessRate);

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
                'rent_basis' => $rentBasis,
                'frozen_half_monthly_rate' => $halfMonthlyRate > 0 ? $halfMonthlyRate : $locked->frozen_half_monthly_rate,
                'frozen_monthly_rate' => $monthlyRate > 0 ? $monthlyRate : $locked->frozen_monthly_rate,
                'frozen_daily_km_allowance' => $kmAllowance,
                'frozen_excess_km_rate' => $excessRate,
                'prepaid_excess_km' => $prepaidExcessKm,
                'prepaid_excess_km_amount' => $prepaidExcessAmount,
                'frozen_agreed_deposit' => $deposit,
                'frozen_deposit_currency' => $depositCurrency,
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
            if (
                array_key_exists('include_vehicle_value_obligation', $data)
                || array_key_exists('vehicle_value_text', $data)
            ) {
                [$includeVehicleValue, $vehicleValueText] = $this->normalizeVehicleValueObligation([
                    'include_vehicle_value_obligation' => array_key_exists('include_vehicle_value_obligation', $data)
                        ? $data['include_vehicle_value_obligation']
                        : $locked->include_vehicle_value_obligation,
                    'vehicle_value_text' => array_key_exists('vehicle_value_text', $data)
                        ? $data['vehicle_value_text']
                        : $locked->frozen_vehicle_value_text,
                ]);
                $locked->fill([
                    'include_vehicle_value_obligation' => $includeVehicleValue,
                    'frozen_vehicle_value_text' => $vehicleValueText,
                ]);
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

        throw new DomainException('لا يمكن تعديل أسعار العقد بعد موافقة الإدارة.');
    }

    /**
     * تحديث التزام ثمن العربية على عقد قيد المراجعة أو معتمد فقط (نص حر متجمّد على العقد).
     *
     * @param  array{include_vehicle_value_obligation?: bool, vehicle_value_text?: string|null}  $data
     */
    public function updateVehicleValueObligation(Contract $contract, array $data, ?int $userId = null): Contract
    {
        if (! in_array($contract->status, ['pending', 'approved'], true)) {
            throw new DomainException('يمكن تعديل التزام ثمن العربية فقط قبل التسليم.');
        }

        return DB::transaction(function () use ($contract, $data, $userId) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['pending', 'approved'], true)) {
                throw new DomainException('يمكن تعديل التزام ثمن العربية فقط قبل التسليم.');
            }

            [$include, $text] = $this->normalizeVehicleValueObligation([
                'include_vehicle_value_obligation' => array_key_exists('include_vehicle_value_obligation', $data)
                    ? $data['include_vehicle_value_obligation']
                    : $locked->include_vehicle_value_obligation,
                'vehicle_value_text' => array_key_exists('vehicle_value_text', $data)
                    ? $data['vehicle_value_text']
                    : $locked->frozen_vehicle_value_text,
            ]);

            $before = [
                'include_vehicle_value_obligation' => (bool) $locked->include_vehicle_value_obligation,
                'frozen_vehicle_value_text' => $locked->frozen_vehicle_value_text,
            ];
            $locked->fill([
                'include_vehicle_value_obligation' => $include,
                'frozen_vehicle_value_text' => $text,
            ])->save();

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'vehicle_value_obligation_updated',
                'record_type' => Contract::class,
                'record_id' => $locked->id,
                'before' => $before,
                'after' => [
                    'include_vehicle_value_obligation' => $include,
                    'frozen_vehicle_value_text' => $text,
                ],
            ]);

            return $locked->fresh(['customer', 'vehicle', 'deposit']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: bool, 1: string|null}
     */
    private function normalizeVehicleValueObligation(array $data): array
    {
        $include = filter_var($data['include_vehicle_value_obligation'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $text = trim((string) ($data['vehicle_value_text'] ?? ''));
        if (! $include) {
            return [false, null];
        }
        if ($text === '') {
            throw new DomainException('اكتب نص ثمن العربية عند تفعيل الالتزام.');
        }
        if (mb_strlen($text) > 500) {
            throw new DomainException('نص ثمن العربية أطول من الحد المسموح (500 حرف).');
        }

        return [true, $text];
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    public function activateOnHandover(Contract $contract, int $odometerOut, ?int $userId = null, array $inspection = []): Contract
    {
        if (! in_array($contract->status, ['approved', 'pending'], true)) {
            throw new DomainException('التسليم يتطلب عقداً بموافقة الإدارة أو قيد المراجعة وقابلاً للتفعيل.');
        }

        if ($contract->status === 'pending') {
            $contract = $this->approve($contract, $userId);
        }

        return DB::transaction(function () use ($contract, $odometerOut, $userId, $inspection) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'approved') {
                throw new DomainException('التسليم يتطلب عقداً بموافقة الإدارة.');
            }

            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->whereKey($locked->vehicle_id)->lockForUpdate()->firstOrFail();
            $this->assertOwnerAgreementCoversRental($vehicle, $locked->planned_ends_at);

            $sourceId = $locked->extended_from_contract_id !== null
                ? (int) $locked->extended_from_contract_id
                : null;

            // تمديد متلاصق: اقفل العقد المصدر تلقائياً عند تسليم عقد التمديد (عداد الخروج = عداد عودة المصدر).
            if ($sourceId) {
                /** @var Contract|null $source */
                $source = Contract::query()->whereKey($sourceId)->lockForUpdate()->first();
                if ($source && $source->status === 'active') {
                    $returnedAt = $locked->starts_at && $locked->starts_at->lte(now())
                        ? $locked->starts_at
                        : now();
                    $this->completeOnReturn(
                        $source,
                        $odometerOut,
                        $returnedAt,
                        $userId,
                        0,
                        ['notes' => 'إقفال تلقائي عند تسليم عقد التمديد '.$locked->number],
                    );
                    $vehicle->refresh();
                }
            }

            $otherActive = Contract::query()
                ->where('vehicle_id', $locked->vehicle_id)
                ->where('status', 'active')
                ->where('id', '!=', $locked->id)
                ->when($sourceId, fn ($q) => $q->where('id', '!=', $sourceId))
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
                $sourceId,
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

            // إثبات حصة كل شهر خدمة من الإيجار فوراً (حتى الشهور اللاحقة مثل ديسمبر من عقد يبدأ في نوفمبر).
            $this->accounting->realignContractRentRecognition($locked->fresh(), $userId, false);

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

            $rentBasis = $this->pricing->resolveRentBasisFromDays($billableDays);
            $periodRent = $this->pricing->periodRent(
                $billableDays,
                $rentBasis,
                (int) $locked->frozen_daily_rate,
                $locked->frozen_half_monthly_rate !== null ? (int) $locked->frozen_half_monthly_rate : null,
                $locked->frozen_monthly_rate !== null ? (int) $locked->frozen_monthly_rate : null,
            );
            $locked->rent_basis = $rentBasis;
            $allowed = $this->pricing->allowedKilometers((int) $locked->frozen_daily_km_allowance, $billableDays);
            $driven = $this->accumulatedDrivenKm($locked, $odometerIn);
            $excess = max(0, $driven - $allowed);
            $prepaidKm = (int) ($locked->prepaid_excess_km ?? 0);
            $prepaidAmount = (int) ($locked->prepaid_excess_km_amount ?? 0);
            $rate = (int) $locked->frozen_excess_km_rate;
            // Additional billable km beyond the prepaid package; unused prepaid is not refunded.
            $billableExtraKm = $this->pricing->billableExcessKilometers($excess, $prepaidKm);
            $excessAmount = $billableExtraKm * $rate;

            $final = $this->pricing->finalTotal([
                'period_rent' => $periodRent,
                'discount' => (int) $locked->discount,
                'prepaid_excess_km_fee' => $prepaidAmount,
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

            // لا تُلغى عقود التمديد المرتبطة — الاستلام المبكر/العادي يحرّر العربية ويُبقي التمديد للموافقة/التسليم.

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
            if ($deposit && in_array($deposit->status, ['received', 'partial'], true)) {
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
     * ن٫١٨: تمديد داخل نفس العقد (معتمد أو نشط) — يطيل planned_ends_at فقط.
     * period_rent = إيجار **مدة التمديد فقط**؛ إيجار المدة السابقة يبقى ويُجمع عليه ثم يُعكس الإجمالي على frozen_*.
     *
     * @param  array{planned_ends_at?: string, extension_days?: int, period_rent: int|string, starts_at?: string}  $data
     */
    public function extend(Contract $source, array $data, ?int $userId = null): Contract
    {
        if (! in_array($source->status, ['approved', 'active'], true)) {
            throw new DomainException('التمديد متاح للعقد المعتمد أو النشط فقط.');
        }

        return DB::transaction(function () use ($source, $data, $userId) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['approved', 'active'], true)) {
                throw new DomainException('التمديد متاح للعقد المعتمد أو النشط فقط.');
            }

            $currentEnd = Carbon::parse($locked->planned_ends_at);
            $extensionDays = isset($data['extension_days']) ? (int) $data['extension_days'] : 0;
            if (! empty($data['planned_ends_at'])) {
                $newEnd = Carbon::parse($data['planned_ends_at']);
            } elseif ($extensionDays > 0) {
                $newEnd = $currentEnd->copy()->addDays($extensionDays);
            } else {
                throw new DomainException('أدخل مدة التمديد بالأيام أو تاريخ النهاية الجديدة.');
            }
            if ($newEnd <= $currentEnd) {
                throw new DomainException('نهاية التمديد يجب أن تكون بعد النهاية الحالية للعقد.');
            }

            $extensionRent = (int) ($data['period_rent'] ?? 0);
            if ($extensionRent < 1) {
                throw new DomainException('إيجار مدة التمديد مطلوب ويجب أن يكون أكبر من صفر.');
            }

            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->whereKey($locked->vehicle_id)->lockForUpdate()->firstOrFail();
            $this->assertOwnerAgreementCoversRental($vehicle, $newEnd);
            $this->assertNoOverlap(
                (int) $locked->vehicle_id,
                $locked->starts_at,
                $newEnd,
                $locked->id,
            );

            $threshold = $this->fractionDayThreshold();
            $previousDays = (int) ($locked->approved_days ?? 0);
            if ($previousDays < 1) {
                $previousDays = $this->pricing->countRentalDays(
                    $locked->starts_at,
                    $currentEnd,
                    $threshold,
                );
            }
            $newDays = $this->pricing->countRentalDays(
                $locked->starts_at,
                $newEnd,
                $threshold,
            );
            $previousBasis = $locked->rent_basis !== null ? (string) $locked->rent_basis : null;
            $normalizedPreviousBasis = $previousBasis !== null && $previousBasis !== ''
                ? $this->pricing->normalizeRentBasis($previousBasis)
                : $this->pricing->resolveRentBasisFromDays(max(1, $previousDays));
            // ن٫١٨: تثبيت الشريحة إلا إذا المدة المضافة وحدها فترة كاملة أعلى.
            $rentBasis = $this->pricing->resolveRentBasisAfterExtension(
                $previousBasis,
                $previousDays,
                $newDays,
            );

            // إيجار المدة السابقة يبقى كما هو؛ المُدخل = إيجار التمديد فقط.
            $previousRent = $this->pricing->periodRent(
                $previousDays,
                $normalizedPreviousBasis,
                (int) $locked->frozen_daily_rate,
                $locked->frozen_half_monthly_rate !== null ? (int) $locked->frozen_half_monthly_rate : null,
                $locked->frozen_monthly_rate !== null ? (int) $locked->frozen_monthly_rate : null,
            );
            $totalRent = $previousRent + $extensionRent;
            $unitRate = $this->pricing->unitRateFromPeriodRent($newDays, $rentBasis, $totalRent);
            if ($unitRate < 1) {
                throw new DomainException('تعذّر استنتاج سعر الشريحة من إيجار التمديد.');
            }

            $ratePatch = match ($rentBasis) {
                ContractPricingService::BASIS_HALF_MONTHLY => ['frozen_half_monthly_rate' => $unitRate],
                ContractPricingService::BASIS_MONTHLY => ['frozen_monthly_rate' => $unitRate],
                default => ['frozen_daily_rate' => $unitRate],
            };

            $allowedKm = $this->pricing->allowedKilometers(
                (int) $locked->frozen_daily_km_allowance,
                $newDays,
            );

            $addedDays = max(0, $newDays - $previousDays);
            $history = is_array($locked->extension_history) ? $locked->extension_history : [];
            $history[] = [
                'extended_at' => now()->toIso8601String(),
                'previous_planned_ends_at' => $currentEnd->toIso8601String(),
                'planned_ends_at' => $newEnd->toIso8601String(),
                'extension_days' => $addedDays,
                'previous_approved_days' => $previousDays,
                'approved_days' => $newDays,
                'previous_rent_basis' => $previousBasis,
                'rent_basis' => $rentBasis,
                'previous_period_rent' => $previousRent,
                'extension_rent' => $extensionRent,
                'period_rent' => $totalRent,
                'unit_rate' => $unitRate,
                'by_user_id' => $userId,
            ];

            $locked->fill([
                'planned_ends_at' => $newEnd,
                'approved_days' => $newDays,
                'rent_basis' => $rentBasis,
                'allowed_km' => $allowedKm,
                'extension_history' => $history,
                // قبل الإقفال الإيجار يُحسب حيّاً من الأسعار المجمّدة بعد عكس (قديم + تمديد).
                'period_rent' => null,
                ...$ratePatch,
            ])->save();

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'extended',
                'record_type' => Contract::class,
                'record_id' => $locked->id,
                'before' => [
                    'planned_ends_at' => $currentEnd->toIso8601String(),
                    'approved_days' => $previousDays,
                    'rent_basis' => $previousBasis,
                    'period_rent' => $previousRent,
                ],
                'after' => [
                    'planned_ends_at' => $newEnd->toIso8601String(),
                    'approved_days' => $newDays,
                    'rent_basis' => $rentBasis,
                    'extension_rent' => $extensionRent,
                    'period_rent' => $totalRent,
                    'allowed_km' => $allowedKm,
                    ...$ratePatch,
                ],
            ]);

            $this->notifyLifecycle(
                'تم تمديد العقد',
                "العقد {$locked->number}: النهاية أصبحت {$newEnd->format('Y-m-d H:i')} ({$newDays} يوماً) · تمديد {$extensionRent} + سابق {$previousRent}.",
                $locked,
                $userId,
            );

            return $locked->fresh(['customer', 'vehicle', 'deposit']);
        });
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

            // التأمين النقدي متابعة تشغيلية فقط — بلا دفعة محاسبية وبلا قيد يومية (يُرد لاحقاً فيلغبط الدفتر).
            return Deposit::query()->create([
                'contract_id' => $contract->id,
                'original_amount' => $amount,
                'status' => 'received',
                'received_at' => now(),
                'receipt_number' => $data['receipt_number'],
                'collection_method' => $data['method'],
                'collection_ref' => $data['collection_ref'] ?? null,
                'deductions_total' => 0,
            ]);
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
        $contract = Contract::query()->find($deposit->contract_id);
        if ($contract) {
            $remaining = $this->ledgerMath->remainingCollectible($contract);
            if ($amount > $remaining) {
                throw new DomainException(
                    sprintf('خصم التأمين يتجاوز المتبقي على العقد (%d ج.م).', $remaining)
                );
            }
        }

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

        if ($contract) {
            // مذكرة تسوية على العقد فقط (method=deposit بلا قيد payment:*) — التأمين نفسه خارج الحسابات.
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

        $deposit->fill([
            'status' => 'returned',
            'returned_at' => now(),
            'return_method' => $method,
            'return_receipt_number' => $receiptNumber,
        ])->save();

        // رد التأمين خارج الحسابات — بلا دفعة وبلا قيد (المبلغ كان محتجزاً تشغيلياً فقط).

        return $deposit->fresh('deductions');
    }

    private function assertNoOverlap(
        int $vehicleId,
        mixed $startsAt,
        mixed $endsAt,
        ?int $ignoreId = null,
        ?int $ignoreExtendedFromId = null,
    ): void {
        $q = Contract::query()->where('vehicle_id', $vehicleId);
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        // تراث ن٫١٨ القديم: عقود تمديد قديمة مربوطة بـ extended_from تتجاهل المصدر عند التداخل.
        if ($ignoreExtendedFromId) {
            $q->where('id', '!=', $ignoreExtendedFromId);
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
            // نفس قاعدة الماسح الآلي: المدير الأعلى + من لديه صلاحية الوحدة فقط.
            $recipients = User::query()
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereHas('role', fn ($role) => $role->where('name', 'super_admin'))
                        ->orWhereHas('role.permissions', fn ($q) => $q->where('key', 'contracts.view'));
                })
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
