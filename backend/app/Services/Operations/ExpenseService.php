<?php

namespace App\Services\Operations;

use App\Models\Account;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Support\CivilDate;
use DomainException;
use Illuminate\Support\Facades\DB;

class ExpenseService
{
    public function __construct(private readonly AccountingService $accounting) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $userId = null): Expense
    {
        $spentOn = CivilDate::toDateString($data['spent_on'] ?? null);
        $this->assertSpentOnNotFuture($spentOn);
        [$year, $month] = CivilDate::yearAndMonth($spentOn);
        $this->accounting->assertMonthOpen($year, $month);
        $this->accounting->ensureChartOfAccounts();

        return DB::transaction(function () use ($data, $userId, $spentOn) {
            $fromPetty = ! empty($data['from_petty_cash']);
            $amount = (int) $data['amount'];
            $this->assertCategoryLinks($data);

            if ($fromPetty) {
                $this->debitPettyCash(
                    (int) ($data['employee_id'] ?? 0),
                    $amount,
                    (string) $spentOn,
                    'مصروف: '.($data['title'] ?? ''),
                    $userId,
                );
            }

            $expense = Expense::query()->create([
                'title' => $data['title'],
                'category' => (string) ($data['category'] ?? 'تشغيل'),
                'amount' => $amount,
                'spent_on' => $spentOn,
                'payment_method' => $fromPetty ? 'petty_cash' : ($data['payment_method'] ?? 'cash'),
                'vehicle_id' => isset($data['vehicle_id']) && $data['vehicle_id'] ? (int) $data['vehicle_id'] : null,
                'work_order_id' => isset($data['work_order_id']) && $data['work_order_id'] ? (int) $data['work_order_id'] : null,
                'employee_id' => $fromPetty ? ($data['employee_id'] ?? null) : ($data['employee_id'] ?? null),
                'from_petty_cash' => $fromPetty,
                'recorded_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->postExpenseAccounting($expense, $userId);

            return $expense->load(['vehicle', 'employee', 'workOrder', 'recorder:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data, ?int $userId = null): Expense
    {
        $spentOn = CivilDate::toDateString($data['spent_on'] ?? $expense->spent_on?->toDateString());
        $this->assertSpentOnNotFuture($spentOn);
        $oldSpentOn = CivilDate::toDateString($expense->spent_on?->toDateString() ?? $spentOn);
        foreach ([$oldSpentOn, $spentOn] as $day) {
            [$year, $month] = CivilDate::yearAndMonth($day);
            $this->accounting->assertMonthOpen($year, $month);
        }
        $this->accounting->ensureChartOfAccounts();

        return DB::transaction(function () use ($expense, $data, $userId, $spentOn) {
            $locked = Expense::query()->whereKey($expense->id)->lockForUpdate()->firstOrFail();
            $this->assertCategoryLinks($data);

            $fromPetty = ! empty($data['from_petty_cash']);
            $amount = (int) $data['amount'];

            $this->voidExpenseSideEffects($locked);

            if ($fromPetty) {
                $this->debitPettyCash(
                    (int) ($data['employee_id'] ?? 0),
                    $amount,
                    $spentOn,
                    'مصروف: '.($data['title'] ?? $locked->title),
                    $userId,
                );
            }

            $locked->update([
                'title' => $data['title'],
                'category' => (string) ($data['category'] ?? 'تشغيل'),
                'amount' => $amount,
                'spent_on' => $spentOn,
                'payment_method' => $fromPetty ? 'petty_cash' : ($data['payment_method'] ?? 'cash'),
                'vehicle_id' => isset($data['vehicle_id']) && $data['vehicle_id'] ? (int) $data['vehicle_id'] : null,
                'work_order_id' => isset($data['work_order_id']) && $data['work_order_id'] ? (int) $data['work_order_id'] : null,
                'employee_id' => $fromPetty ? ($data['employee_id'] ?? null) : null,
                'from_petty_cash' => $fromPetty,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->postExpenseAccounting($locked->fresh() ?? $locked, $userId);

            return ($locked->fresh() ?? $locked)->load(['vehicle', 'employee', 'workOrder', 'recorder:id,name']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSpentOnNotFuture(string $spentOn): void
    {
        $today = now()->timezone(config('app.timezone', 'Africa/Cairo'))->toDateString();
        if ($spentOn > $today) {
            throw new DomainException('لا يمكن تسجيل مصروف بتاريخ مستقبلي.');
        }
    }

    private function assertCategoryLinks(array $data): void
    {
        $category = (string) ($data['category'] ?? 'تشغيل');
        $workOrderId = isset($data['work_order_id']) && $data['work_order_id'] ? (int) $data['work_order_id'] : null;
        $vehicleId = isset($data['vehicle_id']) && $data['vehicle_id'] ? (int) $data['vehicle_id'] : null;

        if ($category === 'صيانة') {
            if (! $vehicleId) {
                throw new DomainException('العربية مطلوبة عند تصنيف المصروف كصيانة.');
            }
            if ($workOrderId) {
                $wo = WorkOrder::query()->find($workOrderId);
                if (! $wo || (int) $wo->vehicle_id !== $vehicleId) {
                    throw new DomainException('أمر الشغل غير مرتبط بهذه العربية.');
                }
            }
        }
    }

    private function debitPettyCash(int $employeeId, int $amount, string $spentOn, string $notes, ?int $userId): void
    {
        if ($employeeId < 1) {
            throw new DomainException('موظف العهدة مطلوب عند الصرف من العهدة.');
        }
        $account = PettyCashAccount::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
        if (! $account) {
            throw new DomainException('لا توجد عهدة نشطة لهذا الموظف.');
        }
        if ($amount > (int) $account->balance) {
            throw new DomainException('رصيد العهدة غير كافٍ.');
        }
        $account->update(['balance' => (int) $account->balance - $amount]);
        PettyCashMovement::query()->create([
            'petty_cash_account_id' => $account->id,
            'type' => 'spend',
            'amount' => $amount,
            'moved_on' => $spentOn,
            'performed_by' => $userId,
            'notes' => $notes,
        ]);
    }

    private function voidExpenseSideEffects(Expense $expense): void
    {
        $sources = [
            'expense:'.$expense->id,
            'custody_spend:expense:'.$expense->id,
        ];
        $entries = JournalEntry::query()->whereIn('source', $sources)->lockForUpdate()->get();
        foreach ($entries as $entry) {
            JournalLine::query()->where('journal_entry_id', $entry->id)->delete();
            $entry->delete();
        }

        if ($expense->from_petty_cash && $expense->employee_id) {
            $account = PettyCashAccount::query()
                ->where('employee_id', $expense->employee_id)
                ->lockForUpdate()
                ->first();
            if ($account) {
                $account->update(['balance' => (int) $account->balance + (int) $expense->amount]);
                PettyCashMovement::query()->create([
                    'petty_cash_account_id' => $account->id,
                    'type' => 'topup',
                    'amount' => (int) $expense->amount,
                    'moved_on' => $expense->spent_on?->toDateString() ?? now()->toDateString(),
                    'performed_by' => null,
                    'notes' => 'عكس تعديل مصروف #'.$expense->id,
                ]);
            }
        }
    }

    private function postExpenseAccounting(Expense $expense, ?int $userId): void
    {
        $category = (string) $expense->category;
        $expenseCode = $category === 'صيانة' ? '5300' : '5100';
        $expenseName = $category === 'صيانة' ? 'مصروف صيانة وقطع' : 'مصروفات تشغيل';
        $expenseAcc = Account::query()->firstOrCreate(
            ['code' => $expenseCode],
            ['name' => $expenseName, 'type' => 'expense', 'is_system' => true, 'is_active' => true],
        );
        $spentOn = $expense->spent_on?->toDateString() ?? now()->toDateString();

        if ($expense->from_petty_cash) {
            $this->accounting->postCustodySpend(
                (int) $expense->amount,
                $expenseCode,
                $expense->vehicle_id,
                'مصروف من العهدة: '.$expense->title,
                $userId,
                $spentOn,
                'expense:'.$expense->id,
            );

            return;
        }

        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $entry = JournalEntry::query()->create([
            'entry_date' => $spentOn,
            'narration' => 'مصروف: '.$expense->title,
            'source' => 'expense:'.$expense->id,
            'posted_by' => $userId,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $expenseAcc->id,
            'debit' => $expense->amount,
            'credit' => 0,
            'vehicle_id' => $expense->vehicle_id,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $cash->id,
            'debit' => 0,
            'credit' => $expense->amount,
        ]);
    }
}
