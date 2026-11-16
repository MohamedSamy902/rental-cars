<?php

namespace App\Services\Contracts;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\ContractVehicleSwap;
use App\Models\Customer;
use App\Models\CustomerCreditMovement;
use App\Models\Deposit;
use App\Models\DepositDeduction;
use App\Models\Expense;
use App\Models\Inspection;
use App\Models\InspectionDamage;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MediaFile;
use App\Models\Payment;
use App\Models\User;
use App\Models\Violation;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use Illuminate\Support\Facades\DB;

/**
 * حذف ناعم للعقد وكل ما يخصه (مدفوعات · قيود · تأمين · مخالفات · فحوصات · صيانة مرتبطة · ميديا).
 * للسوبر أدمن فقط — بلا hard delete.
 */
class ContractSoftDeleteService
{
    /**
     * @return array{
     *   contract_id:int,
     *   counts: array<string,int>
     * }
     */
    public function softDelete(Contract $contract, ?User $actor = null): array
    {
        return DB::transaction(function () use ($contract, $actor) {
            /** @var Contract $locked */
            $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $counts = $this->cascadeForContractIds([(int) $locked->id], $actor?->id);

            $originalNumber = (string) $locked->number;
            $originalStatus = (string) $locked->status;
            $stamp = '__del_'.$locked->id;
            $number = mb_substr($originalNumber, 0, 180).$stamp;
            $locked->forceFill(['number' => $number])->save();
            $locked->delete();
            $counts['contracts'] = 1;

            AuditLog::query()->create([
                'user_id' => $actor?->id,
                'action' => 'soft_deleted',
                'record_type' => Contract::class,
                'record_id' => $locked->id,
                'before' => [
                    'number' => $originalNumber,
                    'status' => $originalStatus,
                ],
                'after' => [
                    'number' => $number,
                    'deleted' => true,
                    'cascade' => $counts,
                ],
            ]);

            return [
                'contract_id' => (int) $locked->id,
                'counts' => $counts,
            ];
        });
    }

    /**
     * مسح كل العقود ناعماً + كل أوامر الشغل/الصيانة (طلب تشغيلي للسوبر أدمن).
     *
     * @return array{contracts:int, work_orders_extra:int, counts: array<string,int>}
     */
    public function softPurgeAll(?User $actor = null): array
    {
        return DB::transaction(function () use ($actor) {
            $ids = Contract::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $totals = $this->emptyCounts();

            foreach ($ids as $id) {
                $contract = Contract::query()->find($id);
                if (! $contract) {
                    continue;
                }
                $result = $this->softDelete($contract, $actor);
                foreach ($result['counts'] as $key => $n) {
                    $totals[$key] = ($totals[$key] ?? 0) + (int) $n;
                }
            }

            $extraWo = $this->softDeleteAllRemainingWorkOrders($actor?->id);
            $totals['work_orders'] = ($totals['work_orders'] ?? 0) + $extraWo;

            AuditLog::query()->create([
                'user_id' => $actor?->id,
                'action' => 'contracts_soft_purged',
                'record_type' => Contract::class,
                'record_id' => 0,
                'after' => [
                    'contracts' => count($ids),
                    'work_orders_extra' => $extraWo,
                    'counts' => $totals,
                ],
            ]);

            return [
                'contracts' => count($ids),
                'work_orders_extra' => $extraWo,
                'counts' => $totals,
            ];
        });
    }

    /**
     * @param  list<int>  $contractIds
     * @return array<string,int>
     */
    private function cascadeForContractIds(array $contractIds, ?int $actorId): array
    {
        $counts = $this->emptyCounts();
        if ($contractIds === []) {
            return $counts;
        }

        $depositIds = Deposit::query()->whereIn('contract_id', $contractIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $inspectionIds = Inspection::query()->whereIn('contract_id', $contractIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $swapWoIds = ContractVehicleSwap::query()
            ->whereIn('contract_id', $contractIds)
            ->whereNotNull('work_order_id')
            ->pluck('work_order_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $paymentIds = Payment::query()
            ->where(function ($q) use ($contractIds, $depositIds) {
                $q->where(function ($qq) use ($contractIds) {
                    $qq->where('link_type', 'contract')->whereIn('link_id', $contractIds);
                });
                if ($depositIds !== []) {
                    $q->orWhere(function ($qq) use ($depositIds) {
                        $qq->where('link_type', 'deposit')->whereIn('link_id', $depositIds);
                    });
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $entryIds = $this->collectJournalEntryIds($contractIds, $depositIds, $paymentIds, $swapWoIds);
        $journalCounts = $this->softDeleteJournalEntries($entryIds);
        $counts['journal_entries'] = $journalCounts['entries'];
        $counts['journal_lines'] = $journalCounts['lines'];

        $counts['payments'] = $this->softDeletePayments($paymentIds);
        $counts['credit_movements'] = $this->softDeleteCreditMovements($contractIds);
        $counts['deposit_deductions'] = $this->softDeleteDepositDeductions($depositIds);
        $counts['deposits'] = $this->softDeleteDeposits($depositIds);
        $counts['inspection_damages'] = $this->softDeleteInspectionDamages($inspectionIds);
        $counts['inspections'] = $this->softDeleteInspections($inspectionIds);
        $counts['violations'] = $this->softDeleteViolations($contractIds);
        $counts['media'] = $this->softDeleteMedia($contractIds, $inspectionIds);
        $woResult = $this->softDeleteWorkOrders($swapWoIds, $actorId);
        $counts['work_orders'] = $woResult['work_orders'];
        $counts['expenses'] = $woResult['expenses'];
        $counts['work_order_journals'] = $woResult['journals'];
        $counts['swaps'] = $this->softDeleteSwaps($contractIds);

        return $counts;
    }

    /**
     * @param  list<int>  $contractIds
     * @param  list<int>  $depositIds
     * @param  list<int>  $paymentIds
     * @param  list<int>  $workOrderIds
     * @return list<int>
     */
    private function collectJournalEntryIds(array $contractIds, array $depositIds, array $paymentIds, array $workOrderIds): array
    {
        $ids = [];

        $contractQuery = JournalEntry::query()->where(function ($q) use ($contractIds) {
            foreach ($contractIds as $id) {
                $q->orWhere('source', 'like', 'rent_recog:'.$id.':%')
                    ->orWhere('source', 'like', 'rent_recog_adj:'.$id.':%')
                    ->orWhere('source', 'contract_close:'.$id)
                    ->orWhere('source', 'contract_close_rent_rev:'.$id);
            }
        });
        $ids = array_merge($ids, $contractQuery->pluck('id')->all());

        if ($paymentIds !== []) {
            $paymentSources = array_map(fn (int $id) => 'payment:'.$id, $paymentIds);
            $ids = array_merge($ids, JournalEntry::query()->whereIn('source', $paymentSources)->pluck('id')->all());
        }

        if ($depositIds !== []) {
            foreach ($depositIds as $depositId) {
                $ids = array_merge(
                    $ids,
                    JournalEntry::query()
                        ->where(function ($q) use ($depositId) {
                            $q->where('source', 'deposit_hold:'.$depositId)
                                ->orWhere('source', 'deposit_return:'.$depositId);
                        })
                        ->pluck('id')
                        ->all()
                );
            }
            $deductionIds = DepositDeduction::query()->whereIn('deposit_id', $depositIds)->pluck('id')->all();
            if ($deductionIds !== []) {
                $applySources = array_map(fn ($id) => 'deposit_apply:'.$id, $deductionIds);
                $ids = array_merge($ids, JournalEntry::query()->whereIn('source', $applySources)->pluck('id')->all());
            }
        }

        if ($workOrderIds !== []) {
            $lineIds = WorkOrderLine::query()->whereIn('work_order_id', $workOrderIds)->pluck('id')->all();
            foreach ($lineIds as $lineId) {
                $ids = array_merge(
                    $ids,
                    JournalEntry::query()->where('source', 'inventory_usage:wol:'.$lineId)->pluck('id')->all()
                );
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param  list<int>  $entryIds
     */
    /**
     * @param  list<int>  $entryIds
     * @return array{entries:int,lines:int}
     */
    private function softDeleteJournalEntries(array $entryIds): array
    {
        if ($entryIds === []) {
            return ['entries' => 0, 'lines' => 0];
        }
        $entries = 0;
        $lines = 0;
        JournalEntry::query()->whereIn('id', $entryIds)->orderBy('id')->each(function (JournalEntry $entry) use (&$entries, &$lines) {
            if ($entry->trashed()) {
                return;
            }
            $lines += (int) JournalLine::query()->where('journal_entry_id', $entry->id)->count();
            $entry->softDeleteReleasingSource();
            $entries++;
        });

        return ['entries' => $entries, 'lines' => $lines];
    }

    /**
     * @param  list<int>  $paymentIds
     */
    private function softDeletePayments(array $paymentIds): int
    {
        if ($paymentIds === []) {
            return 0;
        }
        $n = 0;
        Payment::query()->whereIn('id', $paymentIds)->orderBy('id')->each(function (Payment $payment) use (&$n) {
            if ($payment->trashed()) {
                return;
            }
            $payment->delete();
            $n++;
        });

        return $n;
    }

    /**
     * @param  list<int>  $contractIds
     */
    private function softDeleteCreditMovements(array $contractIds): int
    {
        $n = 0;
        CustomerCreditMovement::query()
            ->whereIn('contract_id', $contractIds)
            ->orderBy('id')
            ->each(function (CustomerCreditMovement $movement) use (&$n) {
                if ($movement->trashed()) {
                    return;
                }
                $customer = Customer::query()->whereKey($movement->customer_id)->lockForUpdate()->first();
                if ($customer) {
                    $amount = (int) $movement->amount;
                    if ($movement->direction === 'credit') {
                        $customer->credit_balance = max(0, (int) $customer->credit_balance - $amount);
                    } else {
                        $customer->credit_balance = (int) $customer->credit_balance + $amount;
                    }
                    $customer->save();
                }
                $movement->delete();
                $n++;
            });

        return $n;
    }

    /**
     * @param  list<int>  $depositIds
     */
    private function softDeleteDepositDeductions(array $depositIds): int
    {
        if ($depositIds === []) {
            return 0;
        }
        $n = 0;
        DepositDeduction::query()->whereIn('deposit_id', $depositIds)->orderBy('id')->each(function (DepositDeduction $row) use (&$n) {
            if ($row->trashed()) {
                return;
            }
            $row->delete();
            $n++;
        });

        return $n;
    }

    /**
     * @param  list<int>  $depositIds
     */
    private function softDeleteDeposits(array $depositIds): int
    {
        if ($depositIds === []) {
            return 0;
        }
        $n = 0;
        Deposit::query()->whereIn('id', $depositIds)->orderBy('id')->each(function (Deposit $row) use (&$n) {
            if ($row->trashed()) {
                return;
            }
            $row->delete();
            $n++;
        });

        return $n;
    }

    /**
     * @param  list<int>  $inspectionIds
     */
    private function softDeleteInspectionDamages(array $inspectionIds): int
    {
        if ($inspectionIds === []) {
            return 0;
        }
        $n = 0;
        InspectionDamage::query()->whereIn('inspection_id', $inspectionIds)->orderBy('id')->each(function (InspectionDamage $row) use (&$n) {
            if ($row->trashed()) {
                return;
            }
            $row->delete();
            $n++;
        });

        return $n;
    }

    /**
     * @param  list<int>  $inspectionIds
     */
    private function softDeleteInspections(array $inspectionIds): int
    {
        if ($inspectionIds === []) {
            return 0;
        }
        $n = 0;
        Inspection::query()->whereIn('id', $inspectionIds)->orderBy('id')->each(function (Inspection $row) use (&$n) {
            if ($row->trashed()) {
                return;
            }
            $row->delete();
            $n++;
        });

        return $n;
    }

    /**
     * @param  list<int>  $contractIds
     */
    private function softDeleteViolations(array $contractIds): int
    {
        $n = 0;
        Violation::query()->whereIn('contract_id', $contractIds)->orderBy('id')->each(function (Violation $row) use (&$n) {
            if ($row->trashed()) {
                return;
            }
            $row->delete();
            $n++;
        });

        return $n;
    }

    /**
     * @param  list<int>  $contractIds
     * @param  list<int>  $inspectionIds
     */
    private function softDeleteMedia(array $contractIds, array $inspectionIds): int
    {
        $n = 0;
        MediaFile::query()
            ->where(function ($q) use ($contractIds, $inspectionIds) {
                $q->where(function ($qq) use ($contractIds) {
                    $qq->where('attachable_type', Contract::class)->whereIn('attachable_id', $contractIds);
                });
                if ($inspectionIds !== []) {
                    $q->orWhere(function ($qq) use ($inspectionIds) {
                        $qq->where('attachable_type', Inspection::class)->whereIn('attachable_id', $inspectionIds);
                    });
                }
            })
            ->orderBy('id')
            ->each(function (MediaFile $media) use (&$n) {
                if ($media->trashed()) {
                    return;
                }
                $media->delete();
                $n++;
            });

        return $n;
    }

    /**
     * @param  list<int>  $workOrderIds
     * @return array{work_orders:int,expenses:int,journals:int}
     */
    private function softDeleteWorkOrders(array $workOrderIds, ?int $actorId): array
    {
        if ($workOrderIds === []) {
            return ['work_orders' => 0, 'expenses' => 0, 'journals' => 0];
        }

        $lineIds = WorkOrderLine::query()->whereIn('work_order_id', $workOrderIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $journalIds = [];
        foreach ($lineIds as $lineId) {
            $journalIds = array_merge(
                $journalIds,
                JournalEntry::query()->where('source', 'inventory_usage:wol:'.$lineId)->pluck('id')->all()
            );
        }
        $journalIds = array_values(array_unique(array_map('intval', $journalIds)));
        $journalCounts = $this->softDeleteJournalEntries($journalIds);
        $journals = $journalCounts['entries'] + $journalCounts['lines'];

        $expenses = 0;
        Expense::query()->whereIn('work_order_id', $workOrderIds)->orderBy('id')->each(function (Expense $expense) use (&$expenses) {
            if ($expense->trashed()) {
                return;
            }
            $expense->delete();
            $expenses++;
        });

        $workOrders = 0;
        WorkOrder::query()->whereIn('id', $workOrderIds)->orderBy('id')->each(function (WorkOrder $wo) use (&$workOrders, $actorId) {
            if ($wo->trashed()) {
                return;
            }
            $wo->delete();
            $workOrders++;
            AuditLog::query()->create([
                'user_id' => $actorId,
                'action' => 'soft_deleted',
                'record_type' => WorkOrder::class,
                'record_id' => $wo->id,
                'after' => ['via' => 'contract_soft_delete'],
            ]);
        });

        return ['work_orders' => $workOrders, 'expenses' => $expenses, 'journals' => $journals];
    }

    /**
     * @param  list<int>  $contractIds
     */
    private function softDeleteSwaps(array $contractIds): int
    {
        $n = 0;
        ContractVehicleSwap::query()->whereIn('contract_id', $contractIds)->orderBy('id')->each(function (ContractVehicleSwap $row) use (&$n) {
            if ($row->trashed()) {
                return;
            }
            $row->delete();
            $n++;
        });

        return $n;
    }

    private function softDeleteAllRemainingWorkOrders(?int $actorId): int
    {
        $ids = WorkOrder::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return 0;
        }
        $result = $this->softDeleteWorkOrders($ids, $actorId);

        return $result['work_orders'];
    }

    /**
     * @return array<string,int>
     */
    private function emptyCounts(): array
    {
        return [
            'contracts' => 0,
            'payments' => 0,
            'journal_entries' => 0,
            'journal_lines' => 0,
            'deposits' => 0,
            'deposit_deductions' => 0,
            'inspections' => 0,
            'inspection_damages' => 0,
            'violations' => 0,
            'media' => 0,
            'work_orders' => 0,
            'expenses' => 0,
            'work_order_journals' => 0,
            'swaps' => 0,
            'credit_movements' => 0,
        ];
    }
}
