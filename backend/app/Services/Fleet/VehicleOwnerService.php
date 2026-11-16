<?php

namespace App\Services\Fleet;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MonthLock;
use App\Models\OwnerMonthDue;
use App\Models\OwnerPayout;
use App\Models\TenantSetting;
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
    public function ensureAccruedThrough(VehicleOwner $owner, string $throughYearMonth, ?int $userId = null, bool $repairProration = false): int
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
        $repaired = 0;
        foreach ($vehicles as $vehicle) {
            // بدون بداية عقد لا يُرحَّل شيء — حتى لا يُحسب شهر كامل من تاريخ الربط بالخطأ.
            $start = $vehicle->owner_agreement_starts_on?->toDateString();
            if ($start === null || $start === '') {
                continue;
            }
            $end = $vehicle->owner_agreement_ends_on?->toDateString();
            $shares = $this->rentSchedule->shares(
                $start,
                $end,
                (int) $vehicle->owner_monthly_amount,
                $today,
                $this->duesCountsFrom(),
            );
            foreach ($shares as $ym => $amount) {
                if ($ym > $throughYearMonth || $amount < 1) {
                    continue;
                }
                $existing = OwnerMonthDue::query()
                    ->where('owner_id', $owner->id)
                    ->where('vehicle_id', $vehicle->id)
                    ->where('year_month', $ym)
                    ->first();
                if ($existing) {
                    if ($repairProration && (int) $existing->amount !== $amount) {
                        $repaired += $this->repairDueAmount($existing, $amount) ? 1 : 0;
                    }
                    continue;
                }
                $this->assertMonthOpenForAccrual($ym);
                $pending[] = [$vehicle, $ym, $amount];
            }
        }

        foreach ($pending as [$vehicle, $ym, $amount]) {
            $this->accrueVehicleMonth($owner, $vehicle, $ym, $userId, $amount);
        }

        return count($pending) + $repaired;
    }

    /**
     * يعكس استحقاقات مرحَّلة تخالف جدول الأيام (شهر غير مكتمل / خارج الاتفاقية) ويصلح المبالغ.
     *
     * @return int عدد الصفوف المعكوسة أو المصحَّحة
     */
    public function reconcileAccrualsToSchedule(VehicleOwner $owner, ?int $userId = null): int
    {
        $this->accounting->ensureChartOfAccounts();
        $this->ensureOwnerAccounts();
        $today = now()->timezone(config('app.timezone', 'Africa/Cairo'))->toDateString();
        $changed = 0;

        $dues = OwnerMonthDue::query()
            ->where('owner_id', $owner->id)
            ->orderBy('year_month')
            ->get();

        foreach ($dues as $due) {
            $vehicle = Vehicle::query()->find($due->vehicle_id);
            if (! $vehicle) {
                $changed += $this->reverseInvalidDue($due, $userId, 'vehicle_missing') ? 1 : 0;

                continue;
            }

            $start = $vehicle->owner_agreement_starts_on?->toDateString();
            $end = $vehicle->owner_agreement_ends_on?->toDateString();
            $shares = $this->rentSchedule->shares(
                $start,
                $end,
                (int) ($vehicle->owner_monthly_amount ?? 0),
                $today,
                $this->duesCountsFrom(),
            );
            $needed = (int) ($shares[$due->year_month] ?? 0);

            if ($needed < 1 || ! $vehicle->is_active) {
                $changed += $this->reverseInvalidDue($due, $userId, 'outside_schedule') ? 1 : 0;

                continue;
            }

            if ((int) $due->amount !== $needed) {
                $changed += $this->repairDueAmount($due, $needed) ? 1 : 0;
            }
        }

        return $changed;
    }

    /**
     * يعكس قيد الاستحقاق ويحذف صف owner_month_dues إن كان الشهر مفتوحاً.
     */
    private function reverseInvalidDue(OwnerMonthDue $due, ?int $userId, string $reason): bool
    {
        try {
            $this->assertMonthOpenForAccrual($due->year_month);
        } catch (DomainException) {
            return false;
        }

        return (bool) DB::transaction(function () use ($due, $userId, $reason) {
            $locked = OwnerMonthDue::query()->whereKey($due->id)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }

            $amount = (int) $locked->amount;
            if ($amount > 0) {
                $payable = Account::query()->where('code', '2100')->firstOrFail();
                $expense = Account::query()->where('code', '5200')->firstOrFail();
                $adjSource = sprintf('owner_accrual_adj:%d:%s', $locked->vehicle_id, $locked->year_month);
                $entry = JournalEntry::query()->where('source', $adjSource)->lockForUpdate()->first();
                if (! $entry) {
                    $entry = JournalEntry::query()->create([
                        'entry_date' => $locked->year_month.'-01',
                        'narration' => "عكس استحقاق مالك غير مستحق · {$locked->year_month}",
                        'source' => $adjSource,
                        'posted_by' => $userId,
                    ]);
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $payable->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'vehicle_id' => $locked->vehicle_id,
                    ]);
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $expense->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'vehicle_id' => $locked->vehicle_id,
                    ]);
                }
            }

            if ($locked->journal_entry_id) {
                $primary = JournalEntry::query()->whereKey($locked->journal_entry_id)->first();
                if ($primary) {
                    JournalLine::query()->where('journal_entry_id', $primary->id)->delete();
                    $primary->delete();
                }
            }

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'owner_month_accrual_reversed',
                'record_type' => OwnerMonthDue::class,
                'record_id' => $locked->id,
                'after' => [
                    'year_month' => $locked->year_month,
                    'amount' => $amount,
                    'vehicle_id' => $locked->vehicle_id,
                    'owner_id' => $locked->owner_id,
                    'reason' => $reason,
                ],
            ]);

            $locked->delete();

            return true;
        });
    }

    /**
     * يصحّح مبلغ استحقاق في شهر غير مقفل ليطابق جدول الأيام المدنية.
     */
    private function repairDueAmount(OwnerMonthDue $due, int $amount): bool
    {
        if ($amount < 1 || (int) $due->amount === $amount) {
            return false;
        }
        try {
            $this->assertMonthOpenForAccrual($due->year_month);
        } catch (DomainException) {
            return false;
        }

        return (bool) DB::transaction(function () use ($due, $amount) {
            $locked = OwnerMonthDue::query()->whereKey($due->id)->lockForUpdate()->first();
            if (! $locked || (int) $locked->amount === $amount) {
                return false;
            }
            $old = (int) $locked->amount;
            $locked->update(['amount' => $amount]);
            if ($locked->journal_entry_id) {
                JournalLine::query()
                    ->where('journal_entry_id', $locked->journal_entry_id)
                    ->where('debit', '>', 0)
                    ->update(['debit' => $amount]);
                JournalLine::query()
                    ->where('journal_entry_id', $locked->journal_entry_id)
                    ->where('credit', '>', 0)
                    ->update(['credit' => $amount]);
            }
            AuditLog::query()->create([
                'user_id' => null,
                'action' => 'owner_month_proration_repaired',
                'record_type' => OwnerMonthDue::class,
                'record_id' => $locked->id,
                'after' => [
                    'year_month' => $locked->year_month,
                    'old_amount' => $old,
                    'amount' => $amount,
                    'vehicle_id' => $locked->vehicle_id,
                    'owner_id' => $locked->owner_id,
                ],
            ]);

            return true;
        });
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

        // صرف تشغيلي = مصروف إيجار ملاك مباشرة مقابل النقدية (بلا ترحيل استحقاق / بلا سقف متبقي).
        return DB::transaction(function () use ($owner, $amount, $paidOn, $method, $vehicleId, $data, $userId) {
            OwnerPayout::query()->where('owner_id', $owner->id)->lockForUpdate()->get();

            $expense = Account::query()->where('code', '5200')->firstOrFail();
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
                'narration' => "صرف شهري لمالك {$owner->full_name}",
                'source' => 'owner_payout:'.$payout->id,
                'posted_by' => $userId,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $expense->id,
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
     * ينظّف دفتر استحقاق الملاك المتراكم (owner_month_dues + قيود 2100/5200 المرتبطة)
     * ويعيد تصنيف صرفات 2100 القديمة إلى مصروف 5200، بدون حذف سجلات الصرف النقدية.
     *
     * @return array{dues_cleared: int, payouts_reclassed: int}
     */
    public function purgeAccrualLedger(?VehicleOwner $onlyOwner = null, ?int $userId = null): array
    {
        $this->accounting->ensureChartOfAccounts();
        $this->ensureOwnerAccounts();

        $duesCleared = 0;
        $payoutsReclassed = 0;
        $today = now()->timezone(config('app.timezone', 'Africa/Cairo'))->toDateString();

        $owners = $onlyOwner
            ? collect([$onlyOwner])
            : VehicleOwner::query()->orderBy('id')->get();

        foreach ($owners as $owner) {
            $result = DB::transaction(function () use ($owner, $userId, $today) {
                $cleared = 0;
                $reclassed = 0;
                $payable = Account::query()->where('code', '2100')->firstOrFail();
                $expense = Account::query()->where('code', '5200')->firstOrFail();

                $dues = OwnerMonthDue::query()
                    ->where('owner_id', $owner->id)
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get();

                foreach ($dues as $due) {
                    $amount = (int) $due->amount;
                    if ($amount > 0) {
                        $source = sprintf('owner_accrual_purge:%d:%s', $due->id, $due->year_month);
                        if (! JournalEntry::query()->where('source', $source)->exists()) {
                            $entry = JournalEntry::query()->create([
                                'entry_date' => $today,
                                'narration' => "تنظيف استحقاق مالك متراكم · {$owner->full_name} · {$due->year_month}",
                                'source' => $source,
                                'posted_by' => $userId,
                            ]);
                            // عكس أثر الاستحقاق: مدين 2100 / دائن 5200
                            JournalLine::query()->create([
                                'journal_entry_id' => $entry->id,
                                'account_id' => $payable->id,
                                'debit' => $amount,
                                'credit' => 0,
                                'vehicle_id' => $due->vehicle_id,
                            ]);
                            JournalLine::query()->create([
                                'journal_entry_id' => $entry->id,
                                'account_id' => $expense->id,
                                'debit' => 0,
                                'credit' => $amount,
                                'vehicle_id' => $due->vehicle_id,
                            ]);
                        }
                    }

                    AuditLog::query()->create([
                        'user_id' => $userId,
                        'action' => 'owner_month_due_purged',
                        'record_type' => OwnerMonthDue::class,
                        'record_id' => $due->id,
                        'before' => [
                            'owner_id' => $due->owner_id,
                            'vehicle_id' => $due->vehicle_id,
                            'year_month' => $due->year_month,
                            'amount' => $due->amount,
                            'journal_entry_id' => $due->journal_entry_id,
                        ],
                    ]);
                    $due->delete();
                    $cleared++;
                }

                $payouts = OwnerPayout::query()
                    ->where('owner_id', $owner->id)
                    ->whereNotNull('journal_entry_id')
                    ->lockForUpdate()
                    ->orderBy('id')
                    ->get();

                foreach ($payouts as $payout) {
                    $amount = (int) $payout->amount;
                    if ($amount < 1 || ! $payout->journal_entry_id) {
                        continue;
                    }
                    $hadPayableDebit = JournalLine::query()
                        ->where('journal_entry_id', $payout->journal_entry_id)
                        ->where('account_id', $payable->id)
                        ->where('debit', '>', 0)
                        ->exists();
                    if (! $hadPayableDebit) {
                        continue;
                    }
                    $source = 'owner_payout_reclass:'.$payout->id;
                    if (JournalEntry::query()->where('source', $source)->exists()) {
                        continue;
                    }
                    $entry = JournalEntry::query()->create([
                        'entry_date' => $today,
                        'narration' => "إعادة تصنيف صرف مالك إلى مصروف · {$owner->full_name}",
                        'source' => $source,
                        'posted_by' => $userId,
                    ]);
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $expense->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'vehicle_id' => $payout->vehicle_id,
                    ]);
                    JournalLine::query()->create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $payable->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'vehicle_id' => $payout->vehicle_id,
                    ]);
                    $reclassed++;
                }

                return ['cleared' => $cleared, 'reclassed' => $reclassed];
            });

            $duesCleared += $result['cleared'];
            $payoutsReclassed += $result['reclassed'];
        }

        return [
            'dues_cleared' => $duesCleared,
            'payouts_reclassed' => $payoutsReclassed,
        ];
    }

    /**
     * @return array{
     *   accrued_total: int,
     *   earned_total: int,
     *   paid_total: int,
     *   overdue: int,
     *   remaining: int,
     *   month: string,
     *   month_accrued: int,
     *   month_paid: int,
     *   vehicles_monthly_total: int,
     *   vehicles_breakdown: list<array{
     *     vehicle_id: int,
     *     plate: string|null,
     *     code: string|null,
     *     monthly: int,
     *     agreement_starts_on: string|null,
     *     agreement_ends_on: string|null,
     *     earned: int,
     *     shares: array<string, int>
     *   }>
     * }
     */
    /**
     * Read-only. Does NOT post journals.
     * earned_total = مجموع نصيب الشهور المدنية المكتملة من جدول العقد (OwnerRentSchedule).
     * الشهر الجاري لا يُحسب أبداً — مثال: بداية عقد ١٥/١١ واليوم ١٦/١١ ⇒ earned=0 ⇒ overdue=0.
     * operational.owner_dues_counts_from يرفع بداية الاحتساب (تشغيل النظام) دون تغيير بداية عقد الإدارة.
     * overdue = max(0, earned_total − paid_total) — فلوس شهور عدّت ولم تُصرف بعد.
     */
    public function balance(VehicleOwner $owner, ?string $yearMonth = null): array
    {
        $tz = config('app.timezone', 'Africa/Cairo');
        $ym = $yearMonth ?: now()->timezone($tz)->format('Y-m');
        $today = now()->timezone($tz)->toDateString();
        $countsFrom = $this->duesCountsFrom();

        $earnedTotal = 0;
        $breakdown = [];
        $vehicles = Vehicle::query()
            ->where('owner_id', $owner->id)
            ->where('is_active', true)
            ->whereNotNull('owner_monthly_amount')
            ->where('owner_monthly_amount', '>', 0)
            ->get(['id', 'code', 'plate', 'owner_monthly_amount', 'owner_agreement_starts_on', 'owner_agreement_ends_on']);

        foreach ($vehicles as $vehicle) {
            $start = $vehicle->owner_agreement_starts_on?->toDateString();
            $end = $vehicle->owner_agreement_ends_on?->toDateString();
            $monthly = (int) $vehicle->owner_monthly_amount;
            $shares = $this->rentSchedule->shares($start, $end, $monthly, $today, $countsFrom);
            $earned = (int) array_sum($shares);
            $earnedTotal += $earned;
            $breakdown[] = [
                'vehicle_id' => (int) $vehicle->id,
                'plate' => $vehicle->plate,
                'code' => $vehicle->code,
                'monthly' => $monthly,
                'agreement_starts_on' => $start,
                'agreement_ends_on' => $end,
                'dues_counts_from' => $countsFrom,
                'earned' => $earned,
                'shares' => $shares,
            ];
        }

        $paidTotal = (int) OwnerPayout::query()->where('owner_id', $owner->id)->sum('amount');
        $overdue = max(0, $earnedTotal - $paidTotal);
        $monthPaid = (int) OwnerPayout::query()
            ->where('owner_id', $owner->id)
            ->whereBetween('paid_on', [$ym.'-01', $ym.'-31'])
            ->sum('amount');
        $vehiclesMonthly = (int) $vehicles->sum('owner_monthly_amount');

        return [
            // accrued_total يطابق earned_total (حساب حي من الجدول) — ليس صفوف owner_month_dues القديمة
            'accrued_total' => $earnedTotal,
            'earned_total' => $earnedTotal,
            'paid_total' => $paidTotal,
            'overdue' => $overdue,
            'remaining' => $overdue,
            'month' => $ym,
            'month_accrued' => 0,
            'month_paid' => $monthPaid,
            'vehicles_monthly_total' => $vehiclesMonthly,
            'dues_counts_from' => $countsFrom,
            'vehicles_breakdown' => $breakdown,
        ];
    }

    /**
     * تاريخ بداية احتساب مستحقات الملاك (تشغيل النظام). null = من بداية عقد الإدارة كما هي.
     */
    public function duesCountsFrom(): ?string
    {
        $raw = TenantSetting::query()->value('operational');
        $ops = is_array($raw) ? $raw : [];
        $value = $ops['owner_dues_counts_from'] ?? null;
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    /** عدد الملاك النشطين الذين عليهم فلوس متأخرة (شهور مكتملة − مصروف). */
    public function countOwnersWithOverdue(): int
    {
        $n = 0;
        foreach (VehicleOwner::query()->where('is_active', true)->orderBy('id')->cursor() as $owner) {
            if ($this->balance($owner)['overdue'] > 0) {
                $n++;
            }
        }

        return $n;
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
