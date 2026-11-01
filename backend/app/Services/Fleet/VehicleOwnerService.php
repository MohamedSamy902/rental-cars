<?php

namespace App\Services\Fleet;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MonthLock;
use App\Models\OwnerMonthDue;
use App\Models\OwnerPayout;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Services\Accounting\AccountingService;
use DateInterval;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class VehicleOwnerService
{
    public function __construct(private readonly AccountingService $accounting) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOwner(array $data): VehicleOwner
    {
        return VehicleOwner::query()->create([
            'full_name' => $data['full_name'],
            'phone' => $data['phone'] ?? null,
            'phone_alt' => $data['phone_alt'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'id_front_path' => $data['id_front_path'] ?? null,
            'id_back_path' => $data['id_back_path'] ?? null,
            'license_front_path' => $data['license_front_path'] ?? null,
            'license_back_path' => $data['license_back_path'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOwner(VehicleOwner $owner, array $data): VehicleOwner
    {
        $owner->update($data);

        return $owner->fresh();
    }

    /**
     * يستحق المبلغ الشهري لكل سيارة من تاريخ الربط حتى الشهر المطلوب (شامل).
     */
    public function ensureAccruedThrough(VehicleOwner $owner, string $throughYearMonth, ?int $userId = null): void
    {
        $through = DateTimeImmutable::createFromFormat('Y-m-d', $throughYearMonth.'-01')
            ?: throw new DomainException('صيغة الشهر غير صحيحة.');
        $this->accounting->ensureChartOfAccounts();
        $this->ensureOwnerAccounts();

        $vehicles = Vehicle::query()
            ->where('owner_id', $owner->id)
            ->where('is_active', true)
            ->whereNotNull('owner_monthly_amount')
            ->where('owner_monthly_amount', '>', 0)
            ->get();

        foreach ($vehicles as $vehicle) {
            $startRaw = $vehicle->owner_since?->format('Y-m-d')
                ?? $vehicle->created_at?->format('Y-m-01')
                ?? $through->format('Y-m-01');
            $cursor = DateTimeImmutable::createFromFormat('Y-m-d', substr($startRaw, 0, 7).'-01')
                ?: $through;

            while ($cursor <= $through) {
                $ym = $cursor->format('Y-m');
                $this->accrueVehicleMonth($owner, $vehicle, $ym, $userId);
                $cursor = $cursor->add(new DateInterval('P1M'));
            }
        }
    }

    public function accrueVehicleMonth(VehicleOwner $owner, Vehicle $vehicle, string $yearMonth, ?int $userId = null): OwnerMonthDue
    {
        $existing = OwnerMonthDue::query()
            ->where('owner_id', $owner->id)
            ->where('vehicle_id', $vehicle->id)
            ->where('year_month', $yearMonth)
            ->first();
        if ($existing) {
            return $existing;
        }

        $amount = (int) ($vehicle->owner_monthly_amount ?? 0);
        if ($amount < 1) {
            throw new DomainException('لا يوجد مبلغ شهري لهذه العربية.');
        }

        [$year, $month] = array_map('intval', explode('-', $yearMonth));
        $locked = MonthLock::query()->where(compact('year', 'month'))->first();
        $isLocked = $locked && $locked->isLocked();

        // شهر مقفل: نثبّت الاستحقاق للرصيد بلا قيد يومية جديد
        if ($isLocked) {
            return OwnerMonthDue::query()->create([
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'year_month' => $yearMonth,
                'amount' => $amount,
                'journal_entry_id' => null,
                'created_by' => $userId,
            ]);
        }

        $this->accounting->ensureChartOfAccounts();
        $this->ensureOwnerAccounts();

        return DB::transaction(function () use ($owner, $vehicle, $yearMonth, $amount, $userId) {
            $payable = Account::query()->where('code', '2100')->firstOrFail();
            $expense = Account::query()->where('code', '5200')->firstOrFail();
            $entryDate = $yearMonth.'-01';

            $entry = JournalEntry::query()->create([
                'entry_date' => $entryDate,
                'narration' => "استحقاق مالك {$owner->full_name} · {$vehicle->code} · {$yearMonth}",
                'source' => "owner_accrual:{$vehicle->id}:{$yearMonth}",
                'posted_by' => $userId,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $expense->id,
                'debit' => $amount,
                'credit' => 0,
                'vehicle_id' => $vehicle->id,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $payable->id,
                'debit' => 0,
                'credit' => $amount,
                'vehicle_id' => $vehicle->id,
            ]);

            return OwnerMonthDue::query()->create([
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'year_month' => $yearMonth,
                'amount' => $amount,
                'journal_entry_id' => $entry->id,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function payout(VehicleOwner $owner, array $data, ?int $userId = null): OwnerPayout
    {
        $amount = (int) $data['amount'];
        if ($amount < 1) {
            throw new DomainException('مبلغ السحب يجب أن يكون أكبر من صفر.');
        }

        $paidOn = (string) ($data['paid_on'] ?? now()->toDateString());
        $date = new DateTimeImmutable($paidOn);
        $this->accounting->assertMonthOpen((int) $date->format('Y'), (int) $date->format('n'));

        $this->ensureAccruedThrough($owner, $date->format('Y-m'), $userId);
        $balance = $this->balance($owner);
        if ($amount > $balance['remaining']) {
            throw new DomainException(sprintf(
                'المبلغ أكبر من المتبقي للمالك (%d).',
                $balance['remaining']
            ));
        }

        $vehicleId = isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;
        if ($vehicleId) {
            $belongs = Vehicle::query()->where('id', $vehicleId)->where('owner_id', $owner->id)->exists();
            if (! $belongs) {
                throw new DomainException('العربية غير مربوطة بهذا المالك.');
            }
        }

        $method = (string) ($data['payment_method'] ?? 'cash');
        $this->accounting->ensureChartOfAccounts();
        $this->ensureOwnerAccounts();

        return DB::transaction(function () use ($owner, $amount, $paidOn, $method, $vehicleId, $data, $userId) {
            $payable = Account::query()->where('code', '2100')->firstOrFail();
            $cashCode = $method === 'transfer' || $method === 'bank' ? '1100' : '1000';
            $cash = Account::query()->where('code', $cashCode)->firstOrFail();

            $payout = OwnerPayout::query()->create([
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicleId,
                'amount' => $amount,
                'paid_on' => $paidOn,
                'payment_method' => $method,
                'notes' => $data['notes'] ?? null,
                'journal_entry_id' => null,
                'recorded_by' => $userId,
            ]);

            $entry = JournalEntry::query()->create([
                'entry_date' => $paidOn,
                'narration' => "صرف لمالك {$owner->full_name}",
                'source' => 'owner_payout:'.$payout->id,
                'posted_by' => $userId,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $payable->id,
                'debit' => $amount,
                'credit' => 0,
                'vehicle_id' => $vehicleId,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cash->id,
                'debit' => 0,
                'credit' => $amount,
                'vehicle_id' => $vehicleId,
            ]);

            $payout->update(['journal_entry_id' => $entry->id]);

            return $payout->fresh('vehicle');
        });
    }

    /**
     * @return array{
     *   accrued_total: int,
     *   paid_total: int,
     *   remaining: int,
     *   month: string,
     *   month_accrued: int,
     *   month_paid: int,
     *   vehicles_monthly_total: int
     * }
     */
    public function balance(VehicleOwner $owner, ?string $yearMonth = null): array
    {
        $ym = $yearMonth ?: now()->format('Y-m');
        $this->ensureAccruedThrough($owner, $ym);

        $accruedTotal = (int) OwnerMonthDue::query()->where('owner_id', $owner->id)->sum('amount');
        $paidTotal = (int) OwnerPayout::query()->where('owner_id', $owner->id)->sum('amount');
        $monthAccrued = (int) OwnerMonthDue::query()->where('owner_id', $owner->id)->where('year_month', $ym)->sum('amount');
        $monthPaid = (int) OwnerPayout::query()
            ->where('owner_id', $owner->id)
            ->whereBetween('paid_on', [$ym.'-01', $ym.'-31'])
            ->sum('amount');
        $vehiclesMonthly = (int) Vehicle::query()
            ->where('owner_id', $owner->id)
            ->where('is_active', true)
            ->sum('owner_monthly_amount');

        return [
            'accrued_total' => $accruedTotal,
            'paid_total' => $paidTotal,
            'remaining' => max(0, $accruedTotal - $paidTotal),
            'month' => $ym,
            'month_accrued' => $monthAccrued,
            'month_paid' => $monthPaid,
            'vehicles_monthly_total' => $vehiclesMonthly,
        ];
    }

    private function ensureOwnerAccounts(): void
    {
        Account::query()->firstOrCreate(
            ['code' => '2100'],
            ['name' => 'مستحقات ملاك العربيات', 'type' => 'liability', 'is_system' => true, 'is_active' => true],
        );
        Account::query()->firstOrCreate(
            ['code' => '5200'],
            ['name' => 'مصروف إيجار ملاك', 'type' => 'expense', 'is_system' => true, 'is_active' => true],
        );
    }
}
