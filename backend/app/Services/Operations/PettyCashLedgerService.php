<?php

namespace App\Services\Operations;

use App\Models\Expense;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Support\MaintenanceContractLabel;

class PettyCashLedgerService
{
    /**
     * Full custody dossier: KPIs + chronological ledger with running balance.
     *
     * @return array<string, mixed>
     */
    public function dossier(PettyCashAccount $account, bool $canSeeContracts = true): array
    {
        $account->load('employee:id,name,job_title,status');

        $movements = PettyCashMovement::query()
            ->where('petty_cash_account_id', $account->id)
            ->orderBy('moved_on')
            ->orderBy('id')
            ->get();

        $expenses = Expense::query()
            ->with(['vehicle:id,brand,model,plate', 'workOrder:id,workshop,status,opened_on,vehicle_id'])
            ->where('from_petty_cash', true)
            ->where('employee_id', $account->employee_id)
            ->orderBy('spent_on')
            ->orderBy('id')
            ->get();

        $workOrderLines = WorkOrderLine::query()
            ->with([
                'workOrder:id,vehicle_id,workshop,opened_on,opened_at,status,notes',
                'workOrder.vehicle:id,brand,model,plate',
            ])
            ->where('from_petty_cash', true)
            ->where('petty_cash_employee_id', $account->employee_id)
            ->orderBy('id')
            ->get();

        $expensesByKey = [];
        foreach ($expenses as $expense) {
            $day = $expense->spent_on?->toDateString() ?? '';
            $expensesByKey[$day.'|'.(int) $expense->amount][] = $expense;
        }

        $woById = [];
        foreach ($workOrderLines as $line) {
            $woId = (int) ($line->work_order_id ?? $line->workOrder?->id ?? 0);
            if ($woId > 0) {
                $woById[$woId][] = $line;
            }
        }

        $usedExpenseIds = [];
        $usedLineIds = [];
        $ledger = [];
        $running = 0;
        $totalTopup = 0;
        $totalSpent = 0;
        $totalReturned = 0;

        foreach ($movements as $movement) {
            $type = (string) $movement->type;
            $amount = (int) $movement->amount;
            $day = $movement->moved_on?->toDateString() ?? now()->toDateString();
            $direction = 'in';
            $category = 'topup';
            $label = 'إيداع / تغذية عهدة';
            $delta = $amount;

            $expense = null;
            $workOrder = null;
            $vehicle = null;
            $parts = [];

            if ($type === 'topup') {
                $totalTopup += $amount;
                $direction = 'in';
                $category = 'topup';
                $label = 'إيداع / تغذية عهدة';
                $delta = $amount;
            } elseif ($type === 'return') {
                $totalReturned += $amount;
                $direction = 'out';
                $category = 'settlement';
                $label = 'رد عهدة / تسوية';
                $delta = -$amount;
            } elseif ($type === 'adjust') {
                // Absolute balance set — treat delta vs running as settlement/adjust.
                $delta = $amount - $running;
                if ($delta >= 0) {
                    $totalTopup += $delta;
                    $direction = 'in';
                    $category = 'topup';
                    $label = 'تعديل رصيد (زيادة)';
                } else {
                    $totalReturned += abs($delta);
                    $direction = 'out';
                    $category = 'settlement';
                    $label = 'تسوية / رد مبلغ';
                }
            } else { // spend
                $direction = 'out';
                $delta = -$amount;
                $totalSpent += $amount;

                $woId = (int) ($movement->work_order_id ?? 0);
                if ($woId > 0) {
                    $category = 'maintenance';
                    $label = 'أمر شغل صيانة';
                    $lines = $woById[$woId] ?? [];
                    $workOrder = WorkOrder::query()->with('vehicle:id,brand,model,plate')->find($woId);
                    $vehicle = $workOrder?->vehicle;
                    foreach ($lines as $line) {
                        $usedLineIds[$line->id] = true;
                        $parts[] = [
                            'id' => $line->id,
                            'description' => MaintenanceContractLabel::present($line->description, $canSeeContracts),
                            'quantity' => $line->quantity,
                            'unit_price' => $line->unit_price,
                            'amount' => (int) ($line->petty_cash_amount ?: ((int) $line->quantity * (int) $line->unit_price)),
                        ];
                    }
                    if ($workOrder?->workshop) {
                        $label = 'أمر شغل صيانة · '.MaintenanceContractLabel::present($workOrder->workshop, $canSeeContracts);
                    }
                } else {
                    $category = 'expense';
                    $label = 'مصروف من العهدة';
                    $bucket = $expensesByKey[$day.'|'.$amount] ?? [];
                    foreach ($bucket as $candidate) {
                        if (isset($usedExpenseIds[$candidate->id])) {
                            continue;
                        }
                        $usedExpenseIds[$candidate->id] = true;
                        $expense = $candidate;
                        break;
                    }
                    if (! $expense) {
                        // Fallback: notes "مصروف: TITLE"
                        $notes = (string) ($movement->notes ?? '');
                        if (str_starts_with($notes, 'مصروف:')) {
                            $title = trim(mb_substr($notes, strlen('مصروف:')));
                            $expense = $expenses->first(function (Expense $e) use ($title, $usedExpenseIds) {
                                return ! isset($usedExpenseIds[$e->id]) && $e->title === $title;
                            });
                            if ($expense) {
                                $usedExpenseIds[$expense->id] = true;
                            }
                        }
                    }
                    if ($expense) {
                        $label = 'مصروف · '.$expense->title;
                        $vehicle = $expense->vehicle;
                    }
                }
            }

            if ($type === 'adjust') {
                $running = $amount;
            } else {
                $running += $delta;
            }

            $ledger[] = [
                'id' => 'm-'.$movement->id,
                'movement_id' => $movement->id,
                'occurred_on' => $day,
                'category' => $category,
                'direction' => $direction,
                'label' => $label,
                'amount' => abs($type === 'adjust' ? $delta : $amount),
                'signed_amount' => $type === 'adjust' ? $delta : ($direction === 'in' ? $amount : -$amount),
                'running_balance' => $running,
                'notes' => $movement->notes,
                'expense' => $expense ? [
                    'id' => $expense->id,
                    'title' => $expense->title,
                    'category' => $expense->category,
                    'amount' => (int) $expense->amount,
                    'spent_on' => $expense->spent_on?->toDateString(),
                    'vehicle_id' => $expense->vehicle_id,
                    'work_order_id' => $expense->work_order_id,
                ] : null,
                'work_order' => $workOrder ? [
                    'id' => $workOrder->id,
                    'workshop' => MaintenanceContractLabel::present($workOrder->workshop, $canSeeContracts),
                    'status' => $workOrder->status,
                    'opened_on' => $workOrder->opened_on?->toDateString() ?? ($workOrder->opened_at?->toDateString()),
                    'vehicle_id' => $workOrder->vehicle_id,
                ] : null,
                'vehicle' => $vehicle ? [
                    'id' => $vehicle->id,
                    'brand' => $vehicle->brand,
                    'model' => $vehicle->model,
                    'plate' => $vehicle->plate,
                    'label' => trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')).' · '.($vehicle->plate ?? ''),
                ] : null,
                'parts' => $parts,
            ];
        }

        // Orphan expenses (no matching movement) — append chronologically at end of day merge would be complex;
        // attach as synthetic outs with running left unchanged visually by recomputing if any remain.
        foreach ($expenses as $expense) {
            if (isset($usedExpenseIds[$expense->id])) {
                continue;
            }
            $amount = (int) $expense->amount;
            $totalSpent += $amount;
            $running -= $amount;
            $day = $expense->spent_on?->toDateString() ?? now()->toDateString();
            $vehicle = $expense->vehicle;
            $ledger[] = [
                'id' => 'e-'.$expense->id,
                'movement_id' => null,
                'occurred_on' => $day,
                'category' => 'expense',
                'direction' => 'out',
                'label' => 'مصروف · '.$expense->title,
                'amount' => $amount,
                'signed_amount' => -$amount,
                'running_balance' => $running,
                'notes' => $expense->notes,
                'expense' => [
                    'id' => $expense->id,
                    'title' => $expense->title,
                    'category' => $expense->category,
                    'amount' => $amount,
                    'spent_on' => $day,
                    'vehicle_id' => $expense->vehicle_id,
                    'work_order_id' => $expense->work_order_id,
                ],
                'work_order' => null,
                'vehicle' => $vehicle ? [
                    'id' => $vehicle->id,
                    'brand' => $vehicle->brand,
                    'model' => $vehicle->model,
                    'plate' => $vehicle->plate,
                    'label' => trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')).' · '.($vehicle->plate ?? ''),
                ] : null,
                'parts' => [],
            ];
        }

        usort($ledger, function (array $a, array $b) {
            $cmp = strcmp((string) $a['occurred_on'], (string) $b['occurred_on']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) $a['id'], (string) $b['id']);
        });

        $movementNet = 0;
        foreach ($ledger as $row) {
            $movementNet += (int) $row['signed_amount'];
        }
        $storedBalance = (int) $account->balance;
        $opening = $storedBalance - $movementNet;
        if ($opening !== 0) {
            $openingDay = $ledger[0]['occurred_on'] ?? $account->created_at?->toDateString() ?? now()->toDateString();
            array_unshift($ledger, [
                'id' => 'opening',
                'movement_id' => null,
                'occurred_on' => $openingDay,
                'category' => 'opening',
                'direction' => $opening >= 0 ? 'in' : 'out',
                'label' => 'رصيد افتتاحي (فرق غير مُسجَّل كحركة)',
                'amount' => abs($opening),
                'signed_amount' => $opening,
                'running_balance' => 0,
                'is_computed' => true,
                'notes' => 'يوضّح الفرق بين رصيد الحساب ومجموع الحركات دون إنشاء حركة أو تعديل الرصيد.',
                'expense' => null,
                'work_order' => null,
                'vehicle' => null,
                'parts' => [],
            ]);
        }

        // Recompute running balance after full chronological sort.
        $running = 0;
        foreach ($ledger as &$row) {
            $running += (int) $row['signed_amount'];
            $row['running_balance'] = $running;
        }
        unset($row);

        return [
            'account' => $account,
            'summary' => [
                'employee_name' => $account->employee?->name,
                'employee_role' => $account->employee?->job_title,
                'limit_amount' => (int) $account->limit_amount,
                'opening_balance' => $opening,
                'total_topup' => $totalTopup,
                'total_spent' => $totalSpent,
                'total_returned' => $totalReturned,
                'balance' => $storedBalance,
                'is_active' => (bool) $account->is_active,
                'ledger_count' => count($ledger),
            ],
            'ledger' => array_reverse($ledger), // newest first for UI
            'movements' => $movements->sortByDesc('id')->values(),
            'linked_expenses' => $expenses->sortByDesc('id')->values(),
            'linked_work_orders' => $workOrderLines->sortByDesc('id')->values(),
        ];
    }
}
