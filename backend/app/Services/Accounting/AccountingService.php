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
use App\Models\SalaryAdvance;
use App\Models\Vehicle;
use App\Services\Contracts\ContractLedgerMath;
use App\Services\Contracts\ContractPricingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    /** @var array<string, Account>|null */
    private ?array $chartByCode = null;

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
        if ($this->chartByCode !== null) {
            return array_values($this->chartByCode);
        }

        $defs = [
            ['code' => '1000', 'name' => 'الصندوق', 'type' => 'asset'],
            ['code' => '1100', 'name' => 'البنك', 'type' => 'asset'],
            ['code' => '1200', 'name' => 'مبالغ مؤجلة السداد', 'type' => 'asset'],
            ['code' => '1300', 'name' => 'تأمينات نقدية محتجزة', 'type' => 'liability'],
            ['code' => '1400', 'name' => 'مخزون قطع وزيوت', 'type' => 'asset'],
            ['code' => '1500', 'name' => 'عهد موظفين', 'type' => 'asset'],
            ['code' => '1600', 'name' => 'سلف موظفين', 'type' => 'asset'],
            ['code' => '2100', 'name' => 'مستحقات ملاك العربيات', 'type' => 'liability'],
            ['code' => '4000', 'name' => 'إيرادات الإيجار', 'type' => 'income'],
            ['code' => '4100', 'name' => 'إيراد كيلومترات زيادة', 'type' => 'income'],
            ['code' => '4200', 'name' => 'إيراد أضرار', 'type' => 'income'],
            ['code' => '4300', 'name' => 'إيراد رسوم تشغيل', 'type' => 'income'],
            ['code' => '4400', 'name' => 'إيراد خدمة تسليم واستلام', 'type' => 'income'],
            ['code' => '5100', 'name' => 'مصروفات تشغيل', 'type' => 'expense'],
            ['code' => '5200', 'name' => 'مصروف إيجار ملاك', 'type' => 'expense'],
            ['code' => '5300', 'name' => 'مصروف صيانة وقطع', 'type' => 'expense'],
            ['code' => '5400', 'name' => 'مصروف رواتب', 'type' => 'expense'],
        ];

        $codes = array_column($defs, 'code');
        $existing = Account::query()->whereIn('code', $codes)->get()->keyBy('code');
        // Fast path: chart already complete — one SELECT, no write (ك٫١ / قراءة فقط).
        if ($existing->count() === count($defs)) {
            $this->chartByCode = [];
            foreach ($defs as $def) {
                $this->chartByCode[$def['code']] = $existing->get($def['code']);
            }

            return array_values($this->chartByCode);
        }

        $rows = [];
        foreach ($defs as $def) {
            $rows[] = Account::query()->updateOrCreate(
                ['code' => $def['code']],
                [...$def, 'is_system' => true, 'is_active' => true],
            );
        }

        $this->chartByCode = [];
        foreach ($rows as $row) {
            $this->chartByCode[$row->code] = $row;
        }

        return $rows;
    }

    public function accountByCode(string $code): Account
    {
        $this->ensureChartOfAccounts();
        if ($this->chartByCode === null || ! isset($this->chartByCode[$code])) {
            return Account::query()->where('code', $code)->firstOrFail();
        }

        return $this->chartByCode[$code];
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
        $this->assertMonthOpen($year, $month);
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

        $periodRent = $this->resolvePeriodRentAmount($contract, $start, $end, $rate);
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
     * Completed contracts keep locked period_rent; open ones always use ن٫٥ live tiers.
     */
    private function resolvePeriodRentAmount(
        Contract $contract,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        int $dailyRate,
    ): int {
        if ((string) $contract->status === 'completed') {
            $stored = (int) ($contract->period_rent ?? 0);
            if ($stored > 0) {
                return $stored;
            }
        }

        $days = max(1, (int) ($contract->approved_days ?: $this->pricing->countRentalDays($start, $end)));

        return $this->pricing->periodRent(
            $days,
            (string) ($contract->rent_basis ?? \App\Services\Contracts\ContractPricingService::BASIS_DAILY),
            $dailyRate,
            $contract->frozen_half_monthly_rate !== null ? (int) $contract->frozen_half_monthly_rate : null,
            $contract->frozen_monthly_rate !== null ? (int) $contract->frozen_monthly_rate : null,
        );
    }

    /**
     * Post, top-up, or reverse rent recognition for one contract/month. Balanced Dr/Cr 1200↔4000.
     * Top-ups require an open month; reverses of excess are allowed even when locked (correction).
     */
    public function postRentRecognition(
        Contract $contract,
        int $year,
        int $month,
        int $neededAmount,
        ?int $userId = null,
        bool $forceTopUpLocked = false,
    ): ?JournalEntry {
        if ($neededAmount < 0) {
            $neededAmount = 0;
        }

        $this->ensureChartOfAccounts();
        $source = sprintf('rent_recog:%d:%04d-%02d', $contract->id, $year, $month);
        $entryDate = CarbonImmutable::create($year, $month, 1, 0, 0, 0, config('app.timezone'))
            ->endOfMonth()
            ->toDateString();

        return DB::transaction(function () use ($contract, $neededAmount, $userId, $source, $entryDate, $year, $month, $forceTopUpLocked) {
            $net = $this->rentRecognizedNet((int) $contract->id, $year, $month);
            if ($net === $neededAmount) {
                $existing = JournalEntry::query()->where('source', $source)->first();

                return $existing?->load('lines.account');
            }

            if ($net > $neededAmount) {
                $this->reverseRentRecognitionExcess(
                    $contract,
                    $year,
                    $month,
                    $net - $neededAmount,
                    $userId,
                );
                $existing = JournalEntry::query()->where('source', $source)->first();

                return $existing?->load('lines.account');
            }

            // Top-up path — never silently write into a locked month unless remediation forces it.
            if (! $forceTopUpLocked) {
                $this->assertMonthOpen($year, $month);
            }

            $existing = JournalEntry::query()->where('source', $source)->lockForUpdate()->first();
            $primaryCredit = 0;
            if ($existing) {
                $rent = $this->accountByCode('4000');
                $primaryCredit = (int) JournalLine::query()
                    ->where('journal_entry_id', $existing->id)
                    ->where('account_id', $rent->id)
                    ->sum('credit');
            }

            // Primary must reach needed + outstanding adj debits so net == needed.
            $targetPrimary = $neededAmount + $this->rentRecognitionAdjustmentDebits((int) $contract->id, $year, $month);
            if ($primaryCredit >= $targetPrimary) {
                return $existing?->load('lines.account');
            }

            $delta = $targetPrimary - $primaryCredit;
            $ar = $this->accountByCode('1200');
            $rent = $this->accountByCode('4000');

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

    /**
     * Align every rent_recog month for a contract to allocateRentByMonth (incl. zeroing months outside the period).
     *
     * @return int number of months that needed a write
     */
    public function realignContractRentRecognition(
        Contract $contract,
        ?int $userId = null,
        bool $forceTopUpLocked = false,
    ): int {
        $this->ensureChartOfAccounts();
        $start = $contract->starts_at;
        $end = $contract->actual_ends_at ?? $contract->planned_ends_at;
        if (! $start || ! $end || $end <= $start) {
            return 0;
        }

        $periodRent = $this->resolvePeriodRentAmount(
            $contract,
            $start,
            $end,
            (int) $contract->frozen_daily_rate,
        );
        if ($periodRent <= 0) {
            return 0;
        }

        $neededByMonth = [];
        foreach ($this->allocation->allocateRentByMonth($start, $end, $periodRent) as $slice) {
            $key = sprintf('%04d-%02d', (int) $slice['year'], (int) $slice['month']);
            $neededByMonth[$key] = (int) $slice['amount'];
        }

        $prefix = sprintf('rent_recog:%d:', $contract->id);
        $existingSources = JournalEntry::query()
            ->where('source', 'like', $prefix.'%')
            ->where('source', 'not like', 'rent_recog_adj:%')
            ->pluck('source');
        foreach ($existingSources as $source) {
            if (preg_match('/^rent_recog:\d+:(\d{4})-(\d{2})$/', (string) $source, $m) !== 1) {
                continue;
            }
            $key = $m[1].'-'.$m[2];
            if (! array_key_exists($key, $neededByMonth)) {
                $neededByMonth[$key] = 0;
            }
        }

        $fixed = 0;
        foreach ($neededByMonth as $key => $needed) {
            [$yearStr, $monthStr] = explode('-', $key);
            $year = (int) $yearStr;
            $month = (int) $monthStr;
            $before = $this->rentRecognizedNet((int) $contract->id, $year, $month);
            if ($before === $needed) {
                continue;
            }
            $this->postRentRecognition($contract, $year, $month, $needed, $userId, $forceTopUpLocked);
            $fixed++;
        }

        return $fixed;
    }

    /** Net rent income (credit − debit) for a contract/month after primary + adjustment entries. */
    public function rentRecognizedNet(int $contractId, int $year, int $month): int
    {
        $rent = $this->accountByCode('4000');
        $sources = [
            sprintf('rent_recog:%d:%04d-%02d', $contractId, $year, $month),
            sprintf('rent_recog_adj:%d:%04d-%02d', $contractId, $year, $month),
        ];
        $entryIds = JournalEntry::query()->whereIn('source', $sources)->pluck('id');
        if ($entryIds->isEmpty()) {
            return 0;
        }

        return (int) JournalLine::query()
            ->whereIn('journal_entry_id', $entryIds)
            ->where('account_id', $rent->id)
            ->sum('credit')
            - (int) JournalLine::query()
                ->whereIn('journal_entry_id', $entryIds)
                ->where('account_id', $rent->id)
                ->sum('debit');
    }

    public function rentRecognitionAdjustmentDebits(int $contractId, int $year, int $month): int
    {
        $rent = $this->accountByCode('4000');
        $adj = JournalEntry::query()
            ->where('source', sprintf('rent_recog_adj:%d:%04d-%02d', $contractId, $year, $month))
            ->first();
        if (! $adj) {
            return 0;
        }

        return (int) JournalLine::query()
            ->where('journal_entry_id', $adj->id)
            ->where('account_id', $rent->id)
            ->sum('debit')
            - (int) JournalLine::query()
                ->where('journal_entry_id', $adj->id)
                ->where('account_id', $rent->id)
                ->sum('credit');
    }

    public function reverseRentRecognitionExcess(
        Contract $contract,
        int $year,
        int $month,
        int $excess,
        ?int $userId = null,
    ): void {
        if ($excess <= 0) {
            return;
        }

        $primarySource = sprintf('rent_recog:%d:%04d-%02d', $contract->id, $year, $month);
        $primary = JournalEntry::query()->where('source', $primarySource)->lockForUpdate()->first();
        if (! $primary) {
            return;
        }

        $adjSource = sprintf('rent_recog_adj:%d:%04d-%02d', $contract->id, $year, $month);
        $adj = JournalEntry::query()->where('source', $adjSource)->lockForUpdate()->first();
        if (! $adj) {
            $adj = JournalEntry::query()->create([
                'entry_date' => $primary->entry_date,
                'narration' => sprintf('تصحيح زيادة إثبات إيجار عقد %s — %04d-%02d', $contract->number, $year, $month),
                'source' => $adjSource,
                'posted_by' => $userId,
            ]);
        }

        $rent = $this->accountByCode('4000');
        $ar = $this->accountByCode('1200');

        JournalLine::query()->create([
            'journal_entry_id' => $adj->id,
            'account_id' => $rent->id,
            'debit' => $excess,
            'credit' => 0,
            'vehicle_id' => $contract->vehicle_id,
            'customer_id' => $contract->customer_id,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $adj->id,
            'account_id' => $ar->id,
            'debit' => 0,
            'credit' => $excess,
            'vehicle_id' => $contract->vehicle_id,
            'customer_id' => $contract->customer_id,
        ]);
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
        $end = $contract->actual_ends_at ?? $contract->planned_ends_at;

        // Extra fees always post into the close month, which must remain open.
        // Rent realign may reverse excess in locked service months (correction) but will not top-up them.
        $closeYear = (int) $end->format('Y');
        $closeMonth = (int) $end->format('n');
        $this->assertMonthOpen($closeYear, $closeMonth);

        return DB::transaction(function () use ($contract, $end, $userId) {
            $source = 'contract_close:'.$contract->id;
            $existing = JournalEntry::query()->where('source', $source)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('lines');
            }

            // Align rent_recog to actual period (tops up open months; reverses excess incl. months outside period).
            $this->realignContractRentRecognition($contract, $userId, false);

            $entry = JournalEntry::query()->create([
                'entry_date' => $end->toDateString(),
                'narration' => 'إقفال عقد '.$contract->number,
                'source' => $source,
                'posted_by' => $userId,
            ]);

            $ar = $this->accountByCode('1200');
            $rent = $this->accountByCode('4000');
            $excess = $this->accountByCode('4100');
            $damage = $this->accountByCode('4200');
            $fees = $this->accountByCode('4300');
            $delivery = $this->accountByCode('4400');

            // Prepaid package + additional excess beyond it both credit إيراد كيلومترات زيادة.
            $excessRevenue = (int) ($contract->prepaid_excess_km_amount ?? 0) + (int) $contract->excess_km_amount;
            $extras = [
                [$excessRevenue, $excess->id],
                [(int) $contract->damage_fee, $damage->id],
                [(int) ($contract->frozen_operating_insurance_fee ?? 0) + (int) $contract->other_fees, $fees->id],
                [(int) ($contract->delivery_service_fee ?? 0), $delivery->id],
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

        $expense = $this->accountByCode('5300');
        $inventory = $this->accountByCode('1400');
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
        $custody = $this->accountByCode('1500');
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
            if ($linkType === 'deposit') {
                throw new DomainException('التأمين النقدي خارج الحسابات — يُسجَّل من مسار العقد فقط بلا دفعة محاسبية.');
            }
            $linked = match ($linkType) {
                'contract' => Contract::query()->whereKey($linkId)->exists(),
                'customer' => Customer::query()->whereKey($linkId)->exists(),
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

    /**
     * @deprecated التأمين النقدي خارج الحسابات — لا يُستدعى من مسار العقد الحي.
     * يبقى للتوافق مع قيود تاريخية / أدوات إصلاح فقط.
     */
    public function postDepositHold(Deposit $deposit, Contract $contract, ?int $userId = null, ?string $method = null): JournalEntry
    {
        $cash = $this->methods->cashAccountCode($method ?? (string) ($deposit->collection_method ?? 'cash'));
        $currency = \App\Support\DepositCurrency::normalize($contract->frozen_deposit_currency ?? null);

        return $this->postTransfer(
            'deposit_hold:'.$deposit->id,
            now()->toDateString(),
            'قبض تأمين نقدي ('.$currency.') للعقد '.$contract->number,
            $cash,
            '1300',
            (int) $deposit->original_amount,
            $userId,
            (int) $contract->vehicle_id,
            (int) $contract->customer_id,
        );
    }

    /**
     * @deprecated التأمين النقدي خارج الحسابات — لا يُستدعى من مسار العقد الحي.
     */
    public function postDepositReturn(Deposit $deposit, int $amount, ?int $userId = null, ?string $method = null): ?JournalEntry
    {
        if ($amount < 1) {
            return null;
        }
        $contract = Contract::query()->find($deposit->contract_id);
        $cash = $this->methods->cashAccountCode($method ?? (string) ($deposit->return_method ?? $deposit->collection_method ?? 'cash'));

        return $this->postTransfer(
            'deposit_return:'.$deposit->id,
            now()->toDateString(),
            'رد تأمين نقدي',
            '1300',
            $cash,
            $amount,
            $userId,
            $contract ? (int) $contract->vehicle_id : null,
            $contract ? (int) $contract->customer_id : null,
        );
    }

    public function postSalaryAdvance(SalaryAdvance $advance, ?int $userId = null): JournalEntry
    {
        $cash = $this->methods->cashAccountCode((string) ($advance->method ?? 'cash'));

        return $this->postTransfer(
            'salary_advance:'.$advance->id,
            $advance->advanced_on?->toDateString() ?? now()->toDateString(),
            'سلفة موظف',
            '1600',
            $cash,
            (int) $advance->amount,
            $userId,
        );
    }

    public function postSalaryAdvanceVoid(SalaryAdvance $advance, ?int $userId = null): JournalEntry
    {
        $cash = $this->methods->cashAccountCode((string) ($advance->method ?? 'cash'));

        return $this->postTransfer(
            'salary_advance_void:'.$advance->id,
            now()->toDateString(),
            'إلغاء سلفة موظف',
            $cash,
            '1600',
            (int) $advance->amount,
            $userId,
        );
    }

    public function postPayrollPayout(PayrollPayout $payout, ?int $userId = null): JournalEntry
    {
        $cash = $this->methods->cashAccountCode((string) ($payout->method ?? 'cash'));
        $paidOn = $payout->paid_on;
        $date = $paidOn instanceof \DateTimeInterface ? $paidOn->format('Y-m-d') : (string) ($paidOn ?: now()->toDateString());

        return $this->postTransfer(
            'payroll_payout:'.$payout->id,
            $date,
            'صرف راتب',
            '5400',
            $cash,
            (int) $payout->amount,
            $userId,
        );
    }

    public function postPayrollAdvanceApply(int $lineId, int $amount, string $date, ?int $userId = null): ?JournalEntry
    {
        if ($amount < 1) {
            return null;
        }

        return $this->postTransfer(
            'payroll_advance_apply:'.$lineId,
            $date,
            'تسوية سلفة على مسير الرواتب',
            '5400',
            '1600',
            $amount,
            $userId,
        );
    }

    /**
     * @deprecated التأمين النقدي خارج الحسابات — خصم التأمين يسجّل مذكرة method=deposit بلا قيد.
     */
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
            $debit = $this->accountByCode($debitCode);
            $credit = $this->accountByCode($creditCode);
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
        $cash = $this->accountByCode($cashCode);
        $ar = $this->accountByCode('1200');
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
     * Calendar months shown on /accounts: past window + current + any future month
     * that still carries active/completed rental days (RSD 3.6 / ن٫٧).
     *
     * @return list<array{0:int,1:int}> year/month pairs ascending
     */
    public function overviewMonthKeys(?CarbonInterface $now = null): array
    {
        $tz = config('app.timezone');
        $today = CarbonImmutable::instance(($now ?? now($tz))->copy()->timezone($tz))->startOfMonth();
        // Sync window: look back far enough for recognition, then overview filters empty past cards.
        $from = $today->subMonths(17);
        $to = $today;

        $horizonRaw = Contract::query()
            ->whereIn('status', ['active', 'completed'])
            ->whereNotNull('starts_at')
            ->selectRaw('MAX(COALESCE(actual_ends_at, planned_ends_at)) as max_end')
            ->value('max_end');
        if ($horizonRaw) {
            $horizon = CarbonImmutable::parse($horizonRaw, $tz)->startOfMonth();
            if ($horizon->greaterThan($to)) {
                $to = $horizon;
            }
        }

        $cap = $today->addMonths(24);
        if ($to->greaterThan($cap)) {
            $to = $cap;
        }

        $keys = [];
        for ($cursor = $from; $cursor->lessThanOrEqualTo($to); $cursor = $cursor->addMonth()) {
            $keys[] = [(int) $cursor->format('Y'), (int) $cursor->format('n')];
        }

        return $keys;
    }

    /**
     * Read-only overview. Rent recognition is written on handover/return/close + cron
     * (`accounting:sync-rent-recognition`) — never on GET (ك٫١ / ي٫٢).
     *
     * @return array{months: list<array<string, mixed>>, accounts: list<Account>, payments_in: int, payments_out: int}
     */
    public function overview(): array
    {
        $this->ensureChartOfAccounts();

        $keysAsc = $this->overviewMonthKeys();
        if ($keysAsc === []) {
            return [
                'months' => [],
                'accounts' => Account::query()->orderBy('code')->get(),
                'payments_in' => (int) Payment::query()->where('direction', 'in')->sum('amount'),
                'payments_out' => (int) Payment::query()->where('direction', 'out')->sum('amount'),
            ];
        }

        $tz = config('app.timezone');
        $currentYm = now($tz)->format('Y-m');
        [$fromY, $fromM] = $keysAsc[0];
        [$toY, $toM] = $keysAsc[array_key_last($keysAsc)];
        $rangeStart = CarbonImmutable::create($fromY, $fromM, 1, 0, 0, 0, $tz)->toDateString();
        $rangeEnd = CarbonImmutable::create($toY, $toM, 1, 0, 0, 0, $tz)->endOfMonth()->toDateString();

        $feeCodes = ['4100', '4200', '4300', '4400'];
        $rentByYm = [];
        $feesByYm = [];
        $journalRows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->whereNull('journal_lines.deleted_at')
            ->whereNull('journal_entries.deleted_at')
            ->whereIn('accounts.code', array_merge(['4000'], $feeCodes))
            ->whereBetween('journal_entries.entry_date', [$rangeStart, $rangeEnd])
            ->get([
                'accounts.code as account_code',
                'journal_entries.entry_date as entry_date',
                'journal_lines.debit as debit',
                'journal_lines.credit as credit',
            ]);
        foreach ($journalRows as $row) {
            $ym = CarbonImmutable::parse((string) $row->entry_date, $tz)->format('Y-m');
            $net = (int) $row->credit - (int) $row->debit;
            if ((string) $row->account_code === '4000') {
                $rentByYm[$ym] = ($rentByYm[$ym] ?? 0) + $net;
            } else {
                $feesByYm[$ym] = ($feesByYm[$ym] ?? 0) + $net;
            }
        }

        $expensesByYm = [];
        foreach (
            Expense::query()
                ->whereBetween('spent_on', [$rangeStart, $rangeEnd])
                ->get(['spent_on', 'amount']) as $expense
        ) {
            $ym = CarbonImmutable::parse((string) $expense->spent_on, $tz)->format('Y-m');
            $expensesByYm[$ym] = ($expensesByYm[$ym] ?? 0) + (int) $expense->amount;
        }

        $payrollByYm = [];
        foreach (
            PayrollPayout::query()
                ->whereBetween('paid_on', [$rangeStart, $rangeEnd])
                ->get(['paid_on', 'amount']) as $payout
        ) {
            $ym = CarbonImmutable::parse((string) $payout->paid_on, $tz)->format('Y-m');
            $payrollByYm[$ym] = ($payrollByYm[$ym] ?? 0) + (int) $payout->amount;
        }

        $completedByYm = [];
        foreach (
            Contract::query()
                ->where('status', 'completed')
                ->whereNotNull('actual_ends_at')
                ->whereBetween('actual_ends_at', [
                    CarbonImmutable::parse($rangeStart, $tz)->startOfDay()->toDateTimeString(),
                    CarbonImmutable::parse($rangeEnd, $tz)->endOfDay()->toDateTimeString(),
                ])
                ->get(['actual_ends_at']) as $contract
        ) {
            $ym = CarbonImmutable::parse($contract->actual_ends_at, $tz)->format('Y-m');
            $completedByYm[$ym] = ($completedByYm[$ym] ?? 0) + 1;
        }

        $lockedByYm = MonthLock::query()
            ->where(function ($q) use ($fromY, $fromM) {
                $q->where('year', '>', $fromY)
                    ->orWhere(function ($qq) use ($fromY, $fromM) {
                        $qq->where('year', $fromY)->where('month', '>=', $fromM);
                    });
            })
            ->where(function ($q) use ($toY, $toM) {
                $q->where('year', '<', $toY)
                    ->orWhere(function ($qq) use ($toY, $toM) {
                        $qq->where('year', $toY)->where('month', '<=', $toM);
                    });
            })
            ->get()
            ->filter(fn (MonthLock $lock) => $lock->isLocked())
            ->mapWithKeys(fn (MonthLock $lock) => [
                sprintf('%04d-%02d', (int) $lock->year, (int) $lock->month) => true,
            ])
            ->all();

        $months = [];
        foreach (array_reverse($keysAsc) as [$year, $month]) {
            $dt = CarbonImmutable::create($year, $month, 1, 0, 0, 0, $tz);
            $ym = sprintf('%04d-%02d', $year, $month);
            $locked = (bool) ($lockedByYm[$ym] ?? false);
            $rentCredit = (int) ($rentByYm[$ym] ?? 0);
            $feesCredit = (int) ($feesByYm[$ym] ?? 0);
            $expensesTotal = (int) ($expensesByYm[$ym] ?? 0);
            $payrollPaid = (int) ($payrollByYm[$ym] ?? 0);
            $completed = (int) ($completedByYm[$ym] ?? 0);

            $isCurrent = $ym === $currentYm;
            $isFuture = $ym > $currentYm;
            $hasActivity = $locked
                || $rentCredit !== 0
                || $feesCredit !== 0
                || $expensesTotal !== 0
                || $payrollPaid !== 0
                || $completed > 0;
            // Always show current month; past/future only when they carry activity.
            if (! $isCurrent && ! $hasActivity) {
                continue;
            }
            if ($isFuture && $rentCredit === 0 && $feesCredit === 0 && ! $locked) {
                continue;
            }

            $months[] = [
                'year' => $year,
                'month' => $month,
                'label' => $dt->locale(app()->getLocale())->translatedFormat('F Y'),
                'locked' => $locked,
                'rent_revenue' => $rentCredit,
                'fees_revenue' => $feesCredit,
                'expenses' => $expensesTotal,
                'payroll_paid' => $payrollPaid,
                // صافي الربح التشغيلي = إيجار مثبت + رسوم − مصروفات − رواتب مصروفة
                'net_operating' => $rentCredit + $feesCredit - $expensesTotal - $payrollPaid,
                'completed_contracts' => $completed,
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
