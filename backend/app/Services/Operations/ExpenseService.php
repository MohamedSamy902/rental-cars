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
        $spentOn = $data['spent_on'] ?? now()->toDateString();
        $date = new \DateTimeImmutable((string) $spentOn);
        $this->accounting->assertMonthOpen((int) $date->format('Y'), (int) $date->format('n'));
        $this->accounting->ensureChartOfAccounts();

        return DB::transaction(function () use ($data, $userId, $spentOn) {
            $fromPetty = ! empty($data['from_petty_cash']);
            $amount = (int) $data['amount'];

            if ($fromPetty) {
                $employeeId = (int) ($data['employee_id'] ?? 0);
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
                    'notes' => 'مصروف: '.($data['title'] ?? ''),
                ]);
            }

            $category = (string) ($data['category'] ?? 'تشغيل');
            $workOrderId = isset($data['work_order_id']) ? (int) $data['work_order_id'] : null;
            $vehicleId = isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null;

            if ($category === 'صيانة') {
                if (! $vehicleId) {
                    throw new DomainException('المركبة مطلوبة عند تصنيف المصروف كصيانة.');
                }
                if ($workOrderId) {
                    $wo = WorkOrder::query()->find($workOrderId);
                    if (! $wo || (int) $wo->vehicle_id !== $vehicleId) {
                        throw new DomainException('أمر الشغل غير مرتبط بهذه المركبة.');
                    }
                }
            }

            $expense = Expense::query()->create([
                'title' => $data['title'],
                'category' => $category,
                'amount' => $amount,
                'spent_on' => $spentOn,
                'payment_method' => $fromPetty ? 'petty_cash' : ($data['payment_method'] ?? 'cash'),
                'vehicle_id' => $vehicleId ?: null,
                'work_order_id' => $workOrderId ?: null,
                'employee_id' => $data['employee_id'] ?? null,
                'from_petty_cash' => $fromPetty,
                'recorded_by' => $userId,
                'notes' => $data['notes'] ?? null,
            ]);

            $expenseCode = $category === 'صيانة' ? '5300' : '5100';
            $expenseName = $category === 'صيانة' ? 'مصروف صيانة وقطع' : 'مصروفات تشغيل';
            $expenseAcc = Account::query()->firstOrCreate(
                ['code' => $expenseCode],
                ['name' => $expenseName, 'type' => 'expense', 'is_system' => true, 'is_active' => true],
            );

            if ($fromPetty) {
                $this->accounting->postCustodySpend(
                    $amount,
                    $expenseCode,
                    $expense->vehicle_id,
                    'مصروف من العهدة: '.$expense->title,
                    $userId,
                    $spentOn,
                    'expense:'.$expense->id,
                );
            } else {
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

            return $expense->load(['vehicle', 'employee', 'workOrder']);
        });
    }
}
