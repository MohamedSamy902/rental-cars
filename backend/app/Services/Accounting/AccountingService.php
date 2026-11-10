<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MonthLock;
use App\Models\Payment;
use App\Models\PayrollPayout;
use App\Models\Vehicle;
use App\Services\Contracts\ContractLedgerMath;
use App\Services\Contracts\ContractPricingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    public function __construct(
        private readonly MonthAllocationService $allocation,
        private readonly ContractPricingService $pricing,
        private readonly ContractLedgerMath $ledgerMath,
        private readonly PaymentMethodNormalizer $methods,
    ) {}

    /**
     * @return list<Account>
     */
    public function ensureChartOfAccounts(): array
    {
        $defs = [
            ['code' => '1000', 'name' => 'الصندوق', 'type' => 'asset'],
            ['code' => '1100', 'name' => 'البنك', 'type' => 'asset'],
            ['code' => '1200', 'name' => 'مبالغ مؤجلة السداد', 'type' => 'asset'],
            ['code' => '1300', 'name' => 'تأمينات نقدية محتجزة', 'type' => 'liability'],
            ['code' => '1400', 'name' => 'مخزون قطع وزيوت', 'type' => 'asset'],
            ['code' => '1500', 'name' => 'عهد موظفين', 'type' => 'asset'],
            ['code' => '2100', 'name' => 'مستحقات ملاك العربيات', 'type' => 'liability'],
            ['code' => '4000', 'name' => 'إيرادات الإيجار', 'type' => 'income'],
            ['code' => '4100', 'name' => 'إيراد كيلومترات زيادة', 'type' => 'income'],
            ['code' => '4200', 'name' => 'إيراد أضرار', 'type' => 'income'],
            ['code' => '4300', 'name' => 'إيراد رسوم تشغيل', 'type' => 'income'],
            ['code' => '5100', 'name' => 'مصروفات تشغيل', 'type' => 'expense'],
            ['code' => '5200', 'name' => 'مصروف إيجار ملاك', 'type' => 'expense'],
            ['code' => '5300', 'name' => 'مصروف صيانة وقطع', 'type' => 'expense'],
        ];

        $rows = [];
        foreach ($defs as $def) {
            $rows[] = Account::query()->updateOrCreate(
                ['code' => $def['code']],
                [...$def, 'is_system' => true, 'is_active' => true],
            );
        }

        return $rows;
    }

    /**
     * يرحّل إثبات الإيجار للشهور المطلوبة (idempotent) حتى تظهر الإيرادات/الميزان.
     *
     * @param  list<array{0:int,1:int}>  $yearMonths
     */
    public function syncRentRecognition(array $yearMonths, ?int $userId = null): void
    {
        foreach ($yearMonths as [$year, $month]) {
            try {
                DB::transaction(function () use ($year, $month, $userId) {
                    $this->recognizeRentForMonth((int) $year, (int) $month, $userId);
                });
            } catch (DomainException) {
                // شهر مقفل أو غير صالح — نتخطّاه
            }
        }
    }

    public function assertMonthOpen(int $year, int $month): void
    {
        $lock = MonthLock::query()->where(compact('year', 'month'))->first();
        if ($lock && $lock->isLocked()) {
            throw new DomainException(sprintf('الشهر %04d-%02d مقفل ولا يمكن تعديله.', $year, $month));
        }
    }

    public function lockMonth(int $year, int $month, ?int $userId = null): MonthLock
    {
        $lock = MonthLock::query()->firstOrNew(compact('year', 'month'));
        if ($lock->isLocked()) {
            throw new DomainException('الشهر مقفل مسبقاً.');
        }

        return DB::transaction(function () use ($lock, $year, $month, $userId) {
            $this->recognizeRentForMonth($year, $month, $userId);

            $lock->fill([
                'locked_by' => $userId,
                'locked_at' => now(),
                'reopen_reason' => null,
            ])->save();

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => 'month_locked',
                'record_type' => MonthLock::class,
                'record_id' => $lock->id,
                'after' => compact('year', 'month'),
            ]);

            return $lock;
        });
    }

    /**
     * Accrue earned rent for every active/completed contract that overlaps the month.
     * Idempotent via rent_recog:{contractId}:{YYYY-MM}; tops up when actual period grows.
     */
    public function recognizeRentForMonth(int $year, int $month, ?int $userId = null): void
    {
        $this->ensureChartOfAccounts();
        $monthStart = CarbonImmutable::create($year, $month, 1, 0, 0, 0, config('app.timezone'));
        $monthEndExclusive = $monthStart->addMonth();

        $contracts = Contract::query()
            ->whereIn('status', ['active', 'completed'])
            ->where('starts_at', '<', $monthEndExclusive)
            ->where(function ($q) use ($monthStart) {
                $q->where(function ($qq) use ($monthStart) {
                    $qq->whereNotNull('actual_ends_at')
                        ->where('actual_ends_at', '>', $monthStart);
                })->orWhere(function ($qq) use ($monthStart) {
                    $qq->whereNull('actual_ends_at')
                        ->where('planned_ends_at', '>', $monthStart);
                });
            })
            ->lockForUpdate()
            ->get();

        foreach ($contracts as $contract) {
            $needed = $this->rentNeededForMonth($contract, $year, $month);
            if ($needed <= 0) {
                continue;
            }
            $this->postRentRecognition($contract, $year, $month, $needed, $userId);
        }
    }

    /**
     * Days × rate (or period allocation share) owed to this calendar month.
     */
    public function rentNeededForMonth(Contract $contract, int $year, int $month): int
    {
        $start = $contract->starts_at;
        if (! $start) {
            return 0;
        }

        $end = $contract->actual_ends_at ?? $contract->planned_ends_at;
        if (! $end || $end <= $start) {
            return 0;
        }

        $rate = (int) $contract->frozen_daily_rate;
        if ($rate <= 0) {
            return 0;
        }

        $periodRent = (int) ($contract->period_rent ?? 0);
        if ($periodRent <= 0) {
            $days = $this->pricing->countRentalDays($start, $end);
            $periodRent = $days * $rate;
        }
        if ($periodRent <= 0) {
            return 0;
        }

        foreach ($this->allocation->allocateRentByMonth($start, $end, $periodRent) as $slice) {
            if ((int) $slice['year'] === $year && (int) $slice['month'] === $month) {
                return (int) $slice['amount'];
            }
        }

        return 0;
    }

    /**
     * Post or top-up rent recognition for one contract/month. Balanced Dr 1200 / Cr 4000.
     */
    public function postRentRecognition(
        Contract $contract,
        int $year,
        int $month,
        int $neededAmount,
        ?int $userId = null,
    ): ?JournalEntry {
        if ($neededAmount <= 0) {
            return null;
        }

        $this->ensureChartOfAccounts();
        $source = sprintf('rent_recog:%d:%04d-%02d', $contract->id, $year, $month);
        $entryDate = CarbonImmutable::create($year, $month, 1, 0, 0, 0, config('app.timezone'))
            ->endOfMonth()
            ->toDateString();

        return DB::transaction(function () use ($contract, $neededAmount, $userId, $source, $entryDate, $year, $month) {
            $existing = JournalEntry::query()->where('source', $source)->lockForUpdate()->first();
            $already = 0;
            if ($existing) {
                $rent = Account::query()->where('code', '4000')->firstOrFail();
                $already = (int) JournalLine::query()
                    ->where('journal_entry_id', $existing->id)
                    ->where('account_id', $rent->id)
                    ->sum('credit');
                if ($already >= $neededAmount) {
                    return $existing->load('lines.account');
                }
            }

            $delta = $neededAmount - $already;
            $ar = Account::query()->where('code', '1200')->firstOrFail();
            $rent = Account::query()->where('code', '4000')->firstOrFail();

            if (! $existing) {
                $existing = JournalEntry::query()->create([
                    'entry_date' => $entryDate,
                    'narration' => sprintf('إثبات إيجار عقد %s — %04d-%02d', $contract->number, $year, $month),
                    'source' => $source,
                    'posted_by' => $userId,
                ]);
            }

            JournalLine::query()->create([
                'journal_entry_id' => $existing->id,
                'account_id' => $ar->id,
                'debit' => $delta,
                'credit' => 0,
                'vehicle_id' => $contract->vehicle_id,
                'customer_id' => $contract->customer_id,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $existing->id,
                'account_id' => $rent->id,
                'debit' => 0,
                'credit' => $delta,
                'vehicle_id' => $contract->vehicle_id,
                'customer_id' => $contract->customer_id,
            ]);

            return $existing->load('lines.account');
        });
    }

    public function reopenMonth(int $year, int $month, string $reason, ?int $userId = null): MonthLock
    {
        $lock = MonthLock::query()->where(compact('year', 'month'))->first();
        if (! $lock || ! $lock->isLocked()) {
            throw new DomainException('الشهر غير مقفل.');
        }
        if (trim($reason) === '') {
            throw new DomainException('سبب إعادة الفتح إلزامي.');
        }

        $lock->fill([
            'locked_at' => null,
            'locked_by' => null,
            'reopen_reason' => $reason,
        ])->save();

        AuditLog::query()->create([
            'user_id' => $userId,
            'action' => 'month_reopened',
            'record_type' => MonthLock::class,
            'record_id' => $lock->id,
            'after' => ['year' => $year, 'month' => $month, 'reason' => $reason],
        ]);

        return $lock;
    }

    /**
     * Post completed-contract revenue into monthly rent recognition + close-month extras.
     * Rent is attributed to service months (entry_date = month end); fees stay on close month.
     */
    public function postContractClose(Contract $contract, ?int $userId = null): JournalEntry
    {
        if ($contract->status !== 'completed') {
            throw new DomainException('الترحيل يتطلب عقداً مكتملاً.');
        }

        $this->ensureChartOfAccounts();
        $start = $contract->starts_at;
        $end = $contract->actual_ends_at ?? $contract->planned_ends_at;
        $slices = $this->allocation->allocateRentByMonth($start, $end, (int) $contract->period_rent);

        // Rent months may already be locked with accruals; close still tops up recognition.
        // Extra fees always post into the close month, which must remain open.
        $closeYear = (int) $end->format('Y');
        $closeMonth = (int) $end->format('n');
        $this->assertMonthOpen($closeYear, $closeMonth);

        return DB::transaction(function () use ($contract, $slices, $end, $userId) {
            $source = 'contract_close:'.$contract->id;
            $existing = JournalEntry::query()->where('source', $source)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('lines');
            }

            foreach ($slices as $slice) {
                if ($slice['amount'] <= 0) {
                    continue;
                }
                $this->postRentRecognition(
                    $contract,
                    (int) $slice['year'],
                    (int) $slice['month'],
                    (int) $slice['amount'],
                    $userId,
                );
            }

            $entry = JournalEntry::query()->create([
                'entry_date' => $end->toDateString(),
                'narration' => 'إقفال عقد '.$contract->number,
                'source' => $source,
                'posted_by' => $userId,
            ]);

            $ar = Account::query()->where('code', '1200')->firstOrFail();
            $rent = Account::query()->where('code', '4000')->firstOrFail();
            $excess = Account::query()->where('code', '4100')->firstOrFail();
            $damage = Account::query()->where('code', '4200')->firstOrFail();
            $fees = Account::query()->where('code', '4300')->firstOrFail();

            $extras = [
                [(int) $contract->excess_km_amount, $excess->id],
                [(int) $contract->damage_fee, $damage->id],
                [(int) ($contract->frozen_operating_insurance_fee ?? 0) + (int) $contract->other_fees, $fees->id],
            ];
            foreach ($extras as [$amount, $accountId]) {
                if ($amount <= 0) {
                    continue;
                }
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $ar->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'vehicle_id' => $contract->vehicle_id,
                    'customer_id' => $contract->customer_id,
                ]);
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $accountId,
                    'debit' => 0,
                    'credit' => $amount,
                    'vehicle_id' => $contract->vehicle_id,
                    'customer_id' => $contract->customer_id,
                ]);
            }

            if ((int) $contract->discount > 0) {
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $rent->id,
                    'debit' => (int) $contract->discount,
                    'credit' => 0,
                    'vehicle_id' => $contract->vehicle_id,
                    'customer_id' => $contract->customer_id,
                ]);
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $ar->id,
                    'debit' => 0,
                    'credit' => (int) $contract->discount,
                    'vehicle_id' => $contract->vehicle_id,
                    'customer_id' => $contract->customer_id,
                ]);
            }

            return $entry->load('lines.account');
        });
    }

    /**
     * Inventory purchase stays off the books; post only on actual usage/issuance.
     * Dr مصروف صيانة وقطع / Cr مخزون قطع وزيوت
     */
    public function postInventoryUsage(
        int $amount,
        ?int $vehicleId = null,
        ?string $narration = null,
        ?int $userId = null,
        ?string $entryDate = null,
        ?string $sourceKey = null,
    ): JournalEntry {
        if ($amount <= 0) {
            throw new DomainException('مبلغ صرف المخزون غير صالح.');
        }
        $this->ensureChartOfAccounts();
        $date = $entryDate ?? now()->toDateString();
        $dt = new \DateTimeImmutable($date);
        $this->assertMonthOpen((int) $dt->format('Y'), (int) $dt->format('n'));

        $expense = Account::query()->where('code', '5300')->firstOrFail();
        $inventory = Account::query()->where('code', '1400')->firstOrFail();
        // source must be unique (idempotency key) — never reuse a bare type string
        $source = $sourceKey
            ? (str_starts_with($sourceKey, 'inventory_usage:') ? $sourceKey : 'inventory_usage:'.$sourceKey)
            : 'inventory_usage:'.uniqid('', true);

        return DB::transaction(function () use ($amount, $vehicleId, $narration, $userId, $date, $expense, $inventory, $source) {
            $existing = JournalEntry::query()->where('source', $source)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('lines.account');
            }

            $entry = JournalEntry::query()->create([
                'entry_date' => $date,
                'narration' => $narration ?: 'صرف مخزون لصيانة',
                'source' => $source,
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
                'account_id' => $inventory->id,
                'debit' => 0,
                'credit' => $amount,
                'vehicle_id' => $vehicleId,
            ]);

            return $entry->load('lines.account');
        });
    }

    /**
     * Custody handovers do not post. Concrete spend from custody posts:
     * Dr expense / Cr عهد موظفين
     */
    public function postCustodySpend(
        int $amount,
        string $expenseAccountCode,
        ?int $vehicleId = null,
        ?string $narration = null,
        ?int $userId = null,
        ?string $entryDate = null,
        ?string $sourceKey = null,
    ): JournalEntry {
        if ($amount <= 0) {
            throw new DomainException('مبلغ الصرف من العهدة غير صالح.');
        }
        $this->ensureChartOfAccounts();
        $date = $entryDate ?? now()->toDateString();
        $dt = new \DateTimeImmutable($date);
        $this->assertMonthOpen((int) $dt->format('Y'), (int) $dt->format('n'));

        $expense = Account::query()->firstOrCreate(
            ['code' => $expenseAccountCode],
            [
                'name' => $expenseAccountCode === '5300' ? 'مصروف صيانة وقطع' : 'مصروفات تشغيل',
                'type' => 'expense',
                'is_system' => true,
                'is_active' => true,
            ],
        );
        $custody = Account::query()->where('code', '1500')->firstOrFail();
        $source = $sourceKey
            ? (str_starts_with($sourceKey, 'custody_spend:') ? $sourceKey : 'custody_spend:'.$sourceKey)
            : 'custody_spend:'.uniqid('', true);

        return DB::transaction(function () use ($amount, $vehicleId, $narration, $userId, $date, $expense, $custody, $source) {
            $existing = JournalEntry::query()->where('source', $source)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('lines.account');
            }

            $entry = JournalEntry::query()->create([
                'entry_date' => $date,
                'narration' => $narration ?: 'صرف من عهدة موظف',
                'source' => $source,
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
                'account_id' => $custody->id,
                'debit' => 0,
                'credit' => $amount,
                'vehicle_id' => $vehicleId,
            ]);

            return $entry->load('lines.account');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordPayment(array $data, ?int $userId = null): Payment
    {
        $paidAt = isset($data['paid_at']) ? new \DateTimeImmutable($data['paid_at']) : now();
        $this->assertMonthOpen((int) $paidAt->format('Y'), (int) $paidAt->format('n'));

        if (! in_array($data['direction'], ['in', 'out'], true)) {
            throw new DomainException('اتجاه الحركة غير صالح.');
        }

        $amount = (int) $data['amount'];
        if ($amount <= 0) {
            throw new DomainException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        $canonical = $this->methods->canonical((string) ($data['method'] ?? ''));
        if ($canonical === 'other') {
            throw new DomainException('طريقة الدفع غير مدعومة.');
        }

        return DB::transaction(function () use ($data, $userId, $paidAt, $amount) {
            $linkType = (string) $data['link_type'];
            $linkId = isset($data['link_id']) ? (int) $data['link_id'] : null;
            if (! $linkId) {
                throw new DomainException('معرّف الربط مطلوب.');
            }
            $linked = match ($linkType) {
                'contract' => Contract::query()->whereKey($linkId)->exists(),
                'customer' => Customer::query()->whereKey($linkId)->exists(),
                'deposit' => Deposit::query()->whereKey($linkId)->exists(),
                'vehicle' => Vehicle::query()->whereKey($linkId)->exists(),
                default => false,
            };
            if (! $linked) {
                throw new DomainException('الربط غير موجود.');
            }

            if ($linkType === 'contract' && $linkId && $data['direction'] === 'in') {
                $contract = Contract::query()->whereKey($linkId)->lockForUpdate()->first();
                if (! $contract) {
                    throw new DomainException('العقد غير موجود.');
                }
                // Lock existing receipt rows so concurrent posts cannot exceed remaining.
                Payment::query()
                    ->where('link_type', 'contract')
                    ->where('link_id', $linkId)
                    ->lockForUpdate()
                    ->get(['id', 'amount', 'direction']);
                $remaining = $this->ledgerMath->remainingCollectible($contract);
                if ($amount > $remaining) {
                    throw new DomainException(
                        sprintf('المبلغ يتجاوز المتبقي على العقد (%d ج.م).', $remaining)
                    );
                }
                // تاريخ التحصيل إلزامي من واجهة التشغيل؛ الـ API يبقى متساهلاً للتوافق مع المسارات القديمة.
            }

            $payment = Payment::query()->create([
                'amount' => $amount,
                'paid_at' => $paidAt,
                'method' => $data['method'],
                'direction' => $data['direction'],
                'reference' => $data['reference'] ?? null,
                'link_type' => $linkType,
                'link_id' => $linkId,
                'user_id' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            // Credit-balance applications are memo allocations — not cash receipts.
            $methodKey = $this->methods->canonical((string) $payment->method);
            if ($linkType === 'contract' && $linkId && ! in_array($methodKey, ['credit', 'deposit'], true)) {
                $this->postPaymentJournal($payment, $userId);
            }

            if ($linkType === 'contract' && $linkId && $data['direction'] === 'in') {
                $fresh = Contract::query()->whereKey($linkId)->lockForUpdate()->first();
                if ($fresh) {
                    $left = $this->ledgerMath->remainingCollectible($fresh);
                    if ($left < 1) {
                        $fresh->balance_due_on = null;
                        $fresh->save();
                    } elseif (! empty($data['balance_due_on'])) {
                        $fresh->balance_due_on = Carbon::parse((string) $data['balance_due_on'])->toDateString();
                        $fresh->save();
                    }
                }
            }

            return $payment;
        });
    }

    public function postDepositHold(Deposit $deposit, Contract $contract, ?int $userId = null): JournalEntry
    {
        return $this->postTransfer(
            'deposit_hold:'.$deposit->id,
            now()->toDateString(),
            'قبض تأمين نقدي للعقد '.$contract->number,
            '1000',
            '1300',
            (int) $deposit->original_amount,
            $userId,
            (int) $contract->vehicle_id,
            (int) $contract->customer_id,
        );
    }

    public function postDepositReturn(Deposit $deposit, int $amount, ?int $userId = null): ?JournalEntry
    {
        if ($amount < 1) {
            return null;
        }
        $contract = Contract::query()->find($deposit->contract_id);

        return $this->postTransfer(
            'deposit_return:'.$deposit->id,
            now()->toDateString(),
            'رد تأمين نقدي',
            '1300',
            '1000',
            $amount,
            $userId,
            $contract ? (int) $contract->vehicle_id : null,
            $contract ? (int) $contract->customer_id : null,
        );
    }

    public function postDepositApply(Deposit $deposit, Contract $contract, int $amount, int $deductionId, ?int $userId = null): JournalEntry
    {
        return $this->postTransfer(
            'deposit_apply:'.$deductionId,
            now()->toDateString(),
            'تسوية تأمين على العقد '.$contract->number,
            '1300',
            '1200',
            $amount,
            $userId,
            (int) $contract->vehicle_id,
            (int) $contract->customer_id,
        );
    }

    public function postCustodyMovement(string $source, string $direction, int $amount, string $date, ?int $userId = null): JournalEntry
    {
        if ($direction === 'in') {
            return $this->postTransfer($source, $date, 'تغذية عهدة', '1500', '1000', $amount, $userId);
        }

        return $this->postTransfer($source, $date, 'رد عهدة', '1000', '1500', $amount, $userId);
    }

    public function postInventoryReceipt(string $source, int $amount, string $date, ?int $userId = null, bool $fromCustody = false): JournalEntry
    {
        return $this->postTransfer(
            $source,
            $date,
            'استلام مخزون',
            '1400',
            $fromCustody ? '1500' : '1000',
            $amount,
            $userId,
        );
    }

    private function postTransfer(
        string $source,
        string $date,
        string $narration,
        string $debitCode,
        string $creditCode,
        int $amount,
        ?int $userId = null,
        ?int $vehicleId = null,
        ?int $customerId = null,
    ): JournalEntry {
        if ($amount < 1) {
            throw new DomainException('مبلغ القيد غير صالح.');
        }
        $this->ensureChartOfAccounts();
        $dt = new \DateTimeImmutable($date);
        $this->assertMonthOpen((int) $dt->format('Y'), (int) $dt->format('n'));

        return DB::transaction(function () use ($source, $date, $narration, $debitCode, $creditCode, $amount, $userId, $vehicleId, $customerId) {
            $existing = JournalEntry::query()->where('source', $source)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $debit = Account::query()->where('code', $debitCode)->firstOrFail();
            $credit = Account::query()->where('code', $creditCode)->firstOrFail();
            $entry = JournalEntry::query()->create([
                'entry_date' => $date,
                'narration' => $narration,
                'source' => $source,
                'posted_by' => $userId,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $debit->id,
                'debit' => $amount,
                'credit' => 0,
                'vehicle_id' => $vehicleId,
                'customer_id' => $customerId,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $credit->id,
                'debit' => 0,
                'credit' => $amount,
                'vehicle_id' => $vehicleId,
                'customer_id' => $customerId,
            ]);

            return $entry;
        });
    }

    /**
     * Cash/bank ↔ AR for contract-linked receipts (no rent recognition here).
     */
    public function postPaymentJournal(Payment $payment, ?int $userId = null): JournalEntry
    {
        $this->ensureChartOfAccounts();
        $source = 'payment:'.$payment->id;
        $existing = JournalEntry::query()->where('source', $source)->lockForUpdate()->first();
        if ($existing) {
            return $existing->load('lines.account');
        }

        $cashCode = $this->methods->cashAccountCode((string) $payment->method);
        $cash = Account::query()->where('code', $cashCode)->firstOrFail();
        $ar = Account::query()->where('code', '1200')->firstOrFail();
        $amount = (int) $payment->amount;
        $date = $payment->paid_at?->toDateString() ?? now()->toDateString();

        $contract = $payment->link_type === 'contract' && $payment->link_id
            ? Contract::query()->find($payment->link_id)
            : null;

        $entry = JournalEntry::query()->create([
            'entry_date' => $date,
            'narration' => $payment->direction === 'in'
                ? 'تحصيل دفعة'.($contract ? ' عقد '.$contract->number : '')
                : 'صرف دفعة'.($contract ? ' عقد '.$contract->number : ''),
            'source' => $source,
            'posted_by' => $userId,
        ]);

        if ($payment->direction === 'in') {
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cash->id,
                'debit' => $amount,
                'credit' => 0,
                'vehicle_id' => $contract?->vehicle_id,
                'customer_id' => $contract?->customer_id,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $ar->id,
                'debit' => 0,
                'credit' => $amount,
                'vehicle_id' => $contract?->vehicle_id,
                'customer_id' => $contract?->customer_id,
            ]);
        } else {
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $ar->id,
                'debit' => $amount,
                'credit' => 0,
                'vehicle_id' => $contract?->vehicle_id,
                'customer_id' => $contract?->customer_id,
            ]);
            JournalLine::query()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cash->id,
                'debit' => 0,
                'credit' => $amount,
                'vehicle_id' => $contract?->vehicle_id,
                'customer_id' => $contract?->customer_id,
            ]);
        }

        return $entry->load('lines.account');
    }

    /**
     * @return array{months: list<array<string, mixed>>, accounts: list<Account>, payments_in: int, payments_out: int}
     */
    public function overview(): array
    {
        $this->ensureChartOfAccounts();

        $months = [];
        $toSync = [];
        for ($i = 0; $i < 6; $i++) {
            $dt = now()->startOfMonth()->subMonths($i);
            $toSync[] = [(int) $dt->format('Y'), (int) $dt->format('n')];
        }
        $this->syncRentRecognition($toSync);

        for ($i = 0; $i < 6; $i++) {
            $dt = now()->startOfMonth()->subMonths($i);
            $year = (int) $dt->format('Y');
            $month = (int) $dt->format('n');
            $lock = MonthLock::query()->where(compact('year', 'month'))->first();
            $rentCredit = (int) JournalLine::query()
                ->whereHas('account', fn ($q) => $q->where('code', '4000'))
                ->whereHas('entry', function ($q) use ($year, $month) {
                    $q->whereYear('entry_date', $year)->whereMonth('entry_date', $month);
                })
                ->sum('credit');
            $feesCredit = (int) JournalLine::query()
                ->whereHas('account', fn ($q) => $q->whereIn('code', ['4100', '4200', '4300']))
                ->whereHas('entry', function ($q) use ($year, $month) {
                    $q->whereYear('entry_date', $year)->whereMonth('entry_date', $month);
                })
                ->sum('credit');
            $expensesTotal = (int) Expense::query()
                ->whereYear('spent_on', $year)
                ->whereMonth('spent_on', $month)
                ->sum('amount');
            $payrollPaid = (int) PayrollPayout::query()
                ->whereYear('paid_on', $year)
                ->whereMonth('paid_on', $month)
                ->sum('amount');
            $months[] = [
                'year' => $year,
                'month' => $month,
                'label' => $dt->translatedFormat('F Y'),
                'locked' => $lock?->isLocked() ?? false,
                'rent_revenue' => $rentCredit,
                'fees_revenue' => $feesCredit,
                'expenses' => $expensesTotal,
                'payroll_paid' => $payrollPaid,
                // صافي الربح التشغيلي = إيجار مثبت + رسوم − مصروفات − رواتب مصروفة
                'net_operating' => $rentCredit + $feesCredit - $expensesTotal - $payrollPaid,
                'completed_contracts' => Contract::query()
                    ->where('status', 'completed')
                    ->whereYear('actual_ends_at', $year)
                    ->whereMonth('actual_ends_at', $month)
                    ->count(),
            ];
        }

        return [
            'months' => $months,
            'accounts' => Account::query()->orderBy('code')->get(),
            'payments_in' => (int) Payment::query()->where('direction', 'in')->sum('amount'),
            'payments_out' => (int) Payment::query()->where('direction', 'out')->sum('amount'),
        ];
    }
}
