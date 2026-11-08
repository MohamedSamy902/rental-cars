<?php

namespace Database\Seeders;

use App\Models\Expense;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use Illuminate\Database\Seeder;

/**
 * Disposable-env coherence after Demo/E2E/Mega (V4-04 / V4-05).
 * Aligns seeded rows so account.balance, movement math, and WO parts_cost match.
 */
class ReconcileSeedFinancialsSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensurePettyMovementsForSeededSpends();
        $this->syncAllPettyBalancesFromMovements();
        $this->reconcileWorkOrderPartsCosts();
    }

    private function reconcileWorkOrderPartsCosts(): void
    {
        WorkOrder::query()->with('lines')->orderBy('id')->each(function (WorkOrder $order) {
            $parts = 0;
            foreach ($order->lines as $line) {
                if ($line->from_petty_cash && (int) $line->petty_cash_amount > 0) {
                    $parts += (int) $line->petty_cash_amount;
                } else {
                    $parts += (int) $line->quantity * (int) $line->unit_price;
                }
            }
            if ((int) $order->parts_cost !== $parts) {
                $order->update(['parts_cost' => $parts]);
            }
        });
    }

    private function ensurePettyMovementsForSeededSpends(): void
    {
        Expense::query()
            ->where('from_petty_cash', true)
            ->whereNotNull('employee_id')
            ->orderBy('id')
            ->each(function (Expense $expense) {
                $account = PettyCashAccount::query()
                    ->where('employee_id', $expense->employee_id)
                    ->where('is_active', true)
                    ->first();
                if (! $account) {
                    $expense->update(['from_petty_cash' => false, 'payment_method' => 'cash']);

                    return;
                }
                $day = $expense->spent_on?->toDateString() ?? now()->toDateString();
                $amount = (int) $expense->amount;
                $note = 'مصروف: '.$expense->title;
                if (PettyCashMovement::query()
                    ->where('petty_cash_account_id', $account->id)
                    ->where('type', 'spend')
                    ->whereDate('moved_on', $day)
                    ->where('amount', $amount)
                    ->where('notes', $note)
                    ->exists()) {
                    return;
                }
                if ($this->available($account) < $amount) {
                    // Cannot fund from custody — keep row as plain cash expense (no orphan).
                    $expense->update([
                        'from_petty_cash' => false,
                        'payment_method' => 'cash',
                        'employee_id' => null,
                    ]);

                    return;
                }
                PettyCashMovement::query()->create([
                    'petty_cash_account_id' => $account->id,
                    'type' => 'spend',
                    'amount' => $amount,
                    'moved_on' => $day,
                    'performed_by' => $expense->recorded_by,
                    'notes' => $note,
                ]);
            });

        WorkOrderLine::query()
            ->where('from_petty_cash', true)
            ->whereNotNull('petty_cash_employee_id')
            ->with('workOrder')
            ->orderBy('id')
            ->each(function (WorkOrderLine $line) {
                $account = PettyCashAccount::query()
                    ->where('employee_id', $line->petty_cash_employee_id)
                    ->where('is_active', true)
                    ->first();
                if (! $account) {
                    $line->update(['from_petty_cash' => false, 'petty_cash_employee_id' => null, 'petty_cash_amount' => 0]);

                    return;
                }
                $amount = (int) ($line->petty_cash_amount ?: ((int) $line->quantity * (int) $line->unit_price));
                if ($amount <= 0) {
                    return;
                }
                $woId = (int) $line->work_order_id;
                if (PettyCashMovement::query()
                    ->where('petty_cash_account_id', $account->id)
                    ->where('type', 'spend')
                    ->where('work_order_id', $woId)
                    ->where('amount', $amount)
                    ->exists()) {
                    return;
                }
                if ($this->available($account) < $amount) {
                    $line->update([
                        'from_petty_cash' => false,
                        'petty_cash_employee_id' => null,
                        'petty_cash_amount' => 0,
                    ]);

                    return;
                }
                $day = $line->workOrder?->opened_on?->toDateString()
                    ?? $line->workOrder?->opened_at?->toDateString()
                    ?? now()->toDateString();
                PettyCashMovement::query()->create([
                    'petty_cash_account_id' => $account->id,
                    'type' => 'spend',
                    'amount' => $amount,
                    'moved_on' => $day,
                    'work_order_id' => $woId ?: null,
                    'performed_by' => null,
                    'notes' => 'أمر شغل #'.$woId.' · '.$line->description,
                ]);
            });
    }

    private function available(PettyCashAccount $account): int
    {
        $topup = (int) PettyCashMovement::query()
            ->where('petty_cash_account_id', $account->id)
            ->where('type', 'topup')
            ->sum('amount');
        $spent = (int) PettyCashMovement::query()
            ->where('petty_cash_account_id', $account->id)
            ->where('type', 'spend')
            ->sum('amount');
        $returned = (int) PettyCashMovement::query()
            ->where('petty_cash_account_id', $account->id)
            ->where('type', 'return')
            ->sum('amount');

        return max(0, $topup - $spent - $returned);
    }

    private function syncAllPettyBalancesFromMovements(): void
    {
        PettyCashAccount::query()->orderBy('id')->each(function (PettyCashAccount $account) {
            $account->update(['balance' => $this->available($account)]);
        });
    }
}
