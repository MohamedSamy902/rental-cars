<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MonthLock;
use App\Models\Payment;
use DomainException;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    public function __construct(private readonly MonthAllocationService $allocation) {}

    /**
     * @return list<Account>
     */
    public function ensureChartOfAccounts(): array
    {
        $defs = [
            ['code' => '1000', 'name' => 'الصندوق', 'type' => 'asset'],
            ['code' => '1100', 'name' => 'البنك', 'type' => 'asset'],
            ['code' => '1200', 'name' => 'ذمم العملاء', 'type' => 'asset'],
            ['code' => '1300', 'name' => 'تأمينات نقدية محتجزة', 'type' => 'liability'],
            ['code' => '1400', 'name' => 'مخزون قطع وزيوت', 'type' => 'asset'],
            ['code' => '1500', 'name' => 'عهد موظفين', 'type' => 'asset'],
            ['code' => '2100', 'name' => 'مستحقات ملاك العربيات', 'type' => 'liability'],
            ['code' => '4000', 'name' => 'إيراد الإيجار', 'type' => 'income'],
            ['code' => '4100', 'name' => 'إيراد كيلومترات زيادة', 'type' => 'income'],
            ['code' => '4200', 'name' => 'إيراد أضرار', 'type' => 'income'],
            ['code' => '4300', 'name' => 'إيراد رسوم تشغيل', 'type' => 'income'],
            ['code' => '5100', 'name' => 'مصروفات تشغيل', 'type' => 'expense'],
            ['code' => '5200', 'name' => 'مصروف إيجار ملاك', 'type' => 'expense'],
            ['code' => '5300', 'name' => 'مصروف صيانة وقطع', 'type' => 'expense'],
        ];

        $rows = [];
        foreach ($defs as $def) {
            $rows[] = Account::query()->firstOrCreate(
                ['code' => $def['code']],
                [...$def, 'is_system' => true, 'is_active' => true],
            );
        }

        return $rows;
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
     * Post completed-contract revenue into monthly journal lines (no violation ledger).
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

        foreach ($slices as $slice) {
            $this->assertMonthOpen($slice['year'], $slice['month']);
        }

        $closeYear = (int) $end->format('Y');
        $closeMonth = (int) $end->format('n');
        $this->assertMonthOpen($closeYear, $closeMonth);

        return DB::transaction(function () use ($contract, $slices, $end, $userId) {
            $entry = JournalEntry::query()->create([
                'entry_date' => $end->toDateString(),
                'narration' => 'إقفال عقد '.$contract->number,
                'source' => 'contract_close:'.$contract->id,
                'posted_by' => $userId,
            ]);

            $ar = Account::query()->where('code', '1200')->firstOrFail();
            $rent = Account::query()->where('code', '4000')->firstOrFail();
            $excess = Account::query()->where('code', '4100')->firstOrFail();
            $damage = Account::query()->where('code', '4200')->firstOrFail();
            $fees = Account::query()->where('code', '4300')->firstOrFail();

            foreach ($slices as $slice) {
                if ($slice['amount'] <= 0) {
                    continue;
                }
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $ar->id,
                    'debit' => $slice['amount'],
                    'credit' => 0,
                    'vehicle_id' => $contract->vehicle_id,
                    'customer_id' => $contract->customer_id,
                ]);
                JournalLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $rent->id,
                    'debit' => 0,
                    'credit' => $slice['amount'],
                    'vehicle_id' => $contract->vehicle_id,
                    'customer_id' => $contract->customer_id,
                ]);
            }

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

        return DB::transaction(function () use ($amount, $vehicleId, $narration, $userId, $date, $expense, $inventory) {
            $entry = JournalEntry::query()->create([
                'entry_date' => $date,
                'narration' => $narration ?: 'صرف مخزون لصيانة',
                'source' => 'inventory_usage',
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

        return DB::transaction(function () use ($amount, $vehicleId, $narration, $userId, $date, $expense, $custody) {
            $entry = JournalEntry::query()->create([
                'entry_date' => $date,
                'narration' => $narration ?: 'صرف من عهدة موظف',
                'source' => 'custody_spend',
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

        return Payment::query()->create([
            'amount' => (int) $data['amount'],
            'paid_at' => $paidAt,
            'method' => $data['method'],
            'direction' => $data['direction'],
            'reference' => $data['reference'] ?? null,
            'link_type' => $data['link_type'],
            'link_id' => $data['link_id'] ?? null,
            'user_id' => $userId,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @return array{months: list<array<string, mixed>>, accounts: list<Account>, payments_in: int, payments_out: int}
     */
    public function overview(): array
    {
        $this->ensureChartOfAccounts();

        $months = [];
        for ($i = 0; $i < 6; $i++) {
            $dt = now()->startOfMonth()->subMonths($i);
            $year = (int) $dt->format('Y');
            $month = (int) $dt->format('n');
            $lock = MonthLock::query()->where(compact('year', 'month'))->first();
            $rentCredit = JournalLine::query()
                ->whereHas('account', fn ($q) => $q->where('code', '4000'))
                ->whereHas('entry', function ($q) use ($year, $month) {
                    $q->whereYear('entry_date', $year)->whereMonth('entry_date', $month);
                })
                ->sum('credit');
            $months[] = [
                'year' => $year,
                'month' => $month,
                'label' => $dt->translatedFormat('F Y'),
                'locked' => $lock?->isLocked() ?? false,
                'rent_revenue' => (int) $rentCredit,
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
