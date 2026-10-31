<?php

namespace App\Services\Maintenance;

use App\Models\InventoryBatch;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Services\Accounting\AccountingService;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Inventory\FifoConsumptionService;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    public function __construct(
        private readonly FifoConsumptionService $fifo,
        private readonly VehicleAvailabilityService $availability,
        private readonly AccountingService $accounting,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function open(array $data, array $lines = [], ?int $userId = null): WorkOrder
    {
        return DB::transaction(function () use ($data, $lines, $userId) {
            $vehicle = Vehicle::query()->findOrFail($data['vehicle_id']);

            if (! empty($data['puts_in_maintenance'])) {
                $conflicts = $vehicle->contracts()
                    ->where(function ($q) {
                        $q->where('status', 'active')
                            ->orWhere(function ($qq) {
                                $qq->whereIn('status', ['pending', 'approved'])
                                    ->where('planned_ends_at', '>=', now());
                            });
                    })
                    ->get(['id', 'number', 'status'])
                    ->map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'status' => $c->status])
                    ->all();
                $this->availability->assertCanEnterMaintenance($conflicts, (bool) ($data['force_after_resolution'] ?? false));
                $vehicle->update(['manual_status' => VehicleAvailabilityService::STATUS_MAINTENANCE]);
            }

            $openedAt = isset($data['opened_at'])
                ? Carbon::parse((string) $data['opened_at'])
                : now();

            $order = WorkOrder::query()->create([
                'vehicle_id' => $vehicle->id,
                'workshop' => $data['workshop'] ?? null,
                'opened_on' => $data['opened_on'] ?? $openedAt->toDateString(),
                'opened_at' => $openedAt,
                'odometer' => $data['odometer'],
                'status' => 'open',
                'puts_in_maintenance' => (bool) ($data['puts_in_maintenance'] ?? true),
                'labor_cost' => (int) ($data['labor_cost'] ?? 0),
                'notes' => $data['notes'] ?? null,
                'next_service_on' => $data['next_service_on'] ?? null,
                'next_service_odometer' => isset($data['next_service_odometer'])
                    ? (int) $data['next_service_odometer']
                    : null,
            ]);

            $parts = 0;
            foreach ($lines as $line) {
                $created = $this->addLine($order, $line, $userId);
                $parts += $created->quantity * $created->unit_price;
            }
            $order->update(['parts_cost' => $parts]);

            return $order->load(['vehicle', 'lines.inventoryItem']);
        });
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function addLine(WorkOrder $order, array $line, ?int $userId = null): WorkOrderLine
    {
        $source = $line['part_source'] ?? 'purchase';
        $unitPrice = (int) ($line['unit_price'] ?? 0);
        $qty = (int) ($line['quantity'] ?? 1);
        $fromPetty = (bool) ($line['from_petty_cash'] ?? false);
        $pettyEmployeeId = isset($line['petty_cash_employee_id']) ? (int) $line['petty_cash_employee_id'] : null;
        $pettyAmount = (int) ($line['petty_cash_amount'] ?? 0);

        $itemId = isset($line['inventory_item_id']) ? (int) $line['inventory_item_id'] : null;
        if ($itemId !== null && $itemId < 1) {
            $itemId = null;
        }

        if ($source === 'stock') {
            if (! $itemId) {
                throw new DomainException('صنف المخزون مطلوب عند الصرف من المخزون.');
            }
            $batches = InventoryBatch::query()
                ->where('inventory_item_id', $itemId)
                ->where('remaining', '>', 0)
                ->orderBy('received_on')
                ->orderBy('id')
                ->get(['id', 'remaining', 'unit_cost'])
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'remaining' => (float) $b->remaining,
                    'unit_cost' => (float) $b->unit_cost,
                ])
                ->all();

            $alloc = $this->fifo->consume($batches, (float) $qty, true);
            $cost = 0.0;
            foreach ($alloc as $a) {
                InventoryBatch::query()->whereKey($a['batch_id'])->decrement('remaining', (int) $a['qty']);
                $cost += $a['qty'] * $a['unit_cost'];
            }
            $unitPrice = (int) round($cost / max(1, $qty));
            $total = (int) round($cost);
        } elseif ($fromPetty) {
            $lineTotal = $unitPrice * $qty;
            $spend = $pettyAmount > 0 ? $pettyAmount : $lineTotal;
            $pettyAmount = $spend;
        }

        $created = WorkOrderLine::query()->create([
            'work_order_id' => $order->id,
            'description' => $line['description'],
            'part_source' => $source,
            'inventory_item_id' => $itemId,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'from_petty_cash' => $fromPetty,
            'petty_cash_employee_id' => $pettyEmployeeId,
            'petty_cash_amount' => $pettyAmount,
        ]);

        // Post journals after the line exists so source keys stay unique + idempotent.
        if ($source === 'stock') {
            $total = (int) ($created->quantity * $created->unit_price);
            if ($total > 0) {
                $this->accounting->postInventoryUsage(
                    $total,
                    (int) $order->vehicle_id,
                    'صرف مخزون لأمر شغل #'.$order->id,
                    $userId,
                    $order->opened_on?->toDateString() ?? now()->toDateString(),
                    'wol:'.$created->id,
                );
            }
        } elseif ($fromPetty) {
            $this->spendFromCustody(
                $pettyEmployeeId,
                (int) $created->petty_cash_amount,
                (int) $order->vehicle_id,
                'شراء قطعة لأمر شغل #'.$order->id,
                $userId,
                $order->opened_on?->toDateString() ?? now()->toDateString(),
                $order->id,
                $created->id,
            );
        }

        return $created;
    }

    public function close(WorkOrder $order): WorkOrder
    {
        return DB::transaction(function () use ($order) {
            $order->update([
                'status' => 'closed',
                'closed_on' => now()->toDateString(),
                'parts_cost' => $order->lines->sum(fn ($l) => $l->quantity * $l->unit_price),
            ]);

            if ($order->puts_in_maintenance) {
                $stillOpen = WorkOrder::query()
                    ->where('vehicle_id', $order->vehicle_id)
                    ->where('id', '!=', $order->id)
                    ->where('puts_in_maintenance', true)
                    ->whereIn('status', ['open', 'in_progress'])
                    ->exists();
                if (! $stillOpen) {
                    Vehicle::query()->whereKey($order->vehicle_id)->update(['manual_status' => null]);
                }
            }

            return $order->fresh(['vehicle', 'lines']);
        });
    }

    private function spendFromCustody(
        ?int $employeeId,
        int $amount,
        int $vehicleId,
        string $notes,
        ?int $userId,
        string $date,
        ?int $workOrderId = null,
        ?int $workOrderLineId = null,
    ): void {
        if (! $employeeId || $employeeId < 1) {
            throw new DomainException('موظف العهدة مطلوب عند الصرف من العهدة.');
        }
        if ($amount <= 0) {
            throw new DomainException('مبلغ العهدة غير صالح.');
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
        $movement = PettyCashMovement::query()->create([
            'petty_cash_account_id' => $account->id,
            'type' => 'spend',
            'amount' => $amount,
            'moved_on' => $date,
            'work_order_id' => $workOrderId,
            'performed_by' => $userId,
            'notes' => $notes,
        ]);

        $this->accounting->postCustodySpend(
            $amount,
            '5300',
            $vehicleId,
            $notes,
            $userId,
            $date,
            $workOrderLineId
                ? 'wol:'.$workOrderLineId
                : 'pcm:'.$movement->id,
        );
    }
}
