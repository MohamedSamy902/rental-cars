<?php

namespace App\Services\Fleet;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MonthLock;
use App\Models\OwnerMonthDue;
use App\Models\OwnerPayout;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Services\Accounting\AccountingService;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class VehicleOwnerService
{
    public function __construct(
        private readonly AccountingService $accounting,
        private readonly OwnerRentSchedule $rentSchedule,
    ) {}

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
            'notes' => $data['notes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateOwner(VehicleOwner $owner, array $data): VehicleOwner
    {
        unset(
            $data['license_front_path'],
            $data['license_back_path'],
            $data['license_number'],
            $data['license_expires_on'],
        );
        $owner->update($data);

        return $owner->fresh();
    }

    /**
     * يرحّل أشهر المالك المنتهية فقط، حتى شهر الاتفاق، وبمبالغ مقسّمة على أيام القاهرة.
     *
     * @return int عدد الاستحقاقات الجديدة
     */
    public function ensureAccruedThrough(VehicleOwner $owner, string $throughYearMonth, ?int $userId = null): int
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $throughYearMonth)) {
            throw new DomainException('صيغة الشهر غير صحيحة.');
        }
        $this->accounting->ensureChartOfAccounts();
        $this->ensureOwnerAccounts();

        $today = now()->timezone(config('app.timezone', 'Africa/Cairo'))->toDateString();
        $vehicles = Vehicle::query()
            ->where('owner_id', $owner->id)
            ->where('is_active', true)
            ->whereNotNull('owner_monthly_amount')
            ->where('owner_monthly_amount', '>', 0)
            ->get();

        $pending = [];
        foreach ($vehicles as $vehicle) {
            $start = $vehicle->owner_agreement_starts_on?->toDateString()
                ?? $vehicle->owner_since?->toDateString();
            $end = $vehicle->owner_agreement_ends_on?->toDateString();
            $shares = $this->rentSchedule->shares($start, $end, (int) $vehicle->owner_monthly_amount, $today);
            foreach ($shares as $ym => $amount) {
                if ($ym > $throughYearMonth || $amount < 1) {
                    continue;
                }
                $exists = OwnerMonthDue::query()
                    ->where('owner_id', $owner->id)
                    ->where('vehicle_id', $vehicle->id)
                    ->where('year_month', $ym)
                    ->exists();
                if ($exists) {
                    continue;
                }
                $this->assertMonthOpenForAccrual($ym);
                $pending[] = [$vehicle, $ym, $amount];
            }
        }

        foreach ($pending as [$vehicle, $ym, $amount]) {
            $this->accrueVehicleMonth($owner, $vehicle, $ym, $userId, $amount);
        }

        return count($pending);
    }

    public function accrueVehicleMonth(VehicleOwner $owner, Vehicle $vehicle, string $yearMonth, ?int $userId = null, ?int $amount = null): OwnerMonthDue
    {
        $existing = OwnerMonthDue::query()
            ->where('owner_id', $owner->id)
            ->where('vehicle_id', $vehicle->id)
            ->where('year_month', $yearMonth)
            ->first();
        if ($existing) {
            return $existing;
        }

        $amount = $amount ?? (int) ($vehicle->owner_monthly_amount ?? 0);
        if ($amount < 1) {
            throw new DomainException('لا يوجد مبلغ شهري لهذه العربية.');
        }
        if (! $vehicle->is_active) {
            throw new DomainException('العربية غير نشطة ولا يُرحَّل لها استحقاق.');
        }

        $this->assertMonthOpenForAccrual($yearMonth);

        $this->accounting->ensureChartOfAccounts();
        $this->ensureOwnerAccounts();

        return DB::transaction(function () use ($owner, $vehicle, $yearMonth, $amount, $userId) {
            $again = OwnerMonthDue::query()
                ->where('owner_id', $owner->id)
                ->where('vehicle_id', $vehicle->id)
                ->where('year_month', $yearMonth)
                ->lockForUpdate()
                ->first();
            if ($again) {
                return $again;
            }

            $payable = Account::query()->where('code', '2100')->firstOrFail();
            $expense = Account::query()->where('code', '5200')->firstOrFail();
            $entryDate = $yearMonth.'-01';
            $source = "owner_accrual:{$vehicle->id}:{$yearMonth}";
            $entry = JournalEntry::query()->where('source', $source)->lockForUpdate()->first();
            if (! $entry) {
                $entry = JournalEntry::query()->create([
                    'entry_date' => $entryDate,
                    'narration' => "استحقاق مالك {$owner->full_name} · {$vehicle->code} · {$yearMonth}",
                    'source' => $source,
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
            }

            $due = OwnerMonthDue::query()->create([
                'owner_id' => $owner->id,
                'vehicle_id' => $vehicle->id,
                'year_month' => $yearMonth,
                'amount' => $amount,
                'journal_entry_id' => $entry->id,
                'created_by' => $userId,
            ]);

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'owner_month_accrued',
                'record_type' => OwnerMonthDue::class,
                'record_id' => $due->id,
                'after' => [
                    'owner_id' => $owner->id,
                    'owner_name' => $owner->full_name,
                    'vehicle_id' => $vehicle->id,
                    'vehicle_code' => $vehicle->code,
                    'year_month' => $yearMonth,
                    'year' => (int) substr($yearMonth, 0, 4),
                    'month' => (int) substr($yearMonth, 5, 2),
                    'amount' => $amount,
                    'journal_entry_id' => $entry->id,
                ],
            ]);

            return $due;
        });
    }

    /**
     * Locked months are rejected before any due or journal is written.
     */
    private function assertMonthOpenForAccrual(string $yearMonth): void
    {
        [$year, $month] = array_map('intval', explode('-', $yearMonth));
        $locked = MonthLock::query()->where(compact('year', 'month'))->first();
        if ($locked && $locked->isLocked()) {
            throw new DomainException(sprintf('الشهر %s مقفل ولا يُرحَّل إليه استحقاق مالك.', $yearMonth));
        }
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
    /**
     * Read-only balance from existing dues/payouts. Does NOT accrue missing months
     * (V4-03 — GETs must not post). Use ensureAccruedThrough via payout / artisan.
     */
    public function balance(VehicleOwner $owner, ?string $yearMonth = null): array
    {
        $ym = $yearMonth ?: now()->format('Y-m');

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
