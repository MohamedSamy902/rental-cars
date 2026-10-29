<?php

namespace App\Services\Maintenance;

use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Services\Fleet\VehicleAvailabilityService;
use App\Services\Inventory\FifoConsumptionService;
use App\Models\InventoryBatch;
use DomainException;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    public function __construct(
        private readonly FifoConsumptionService $fifo,
        private readonly VehicleAvailabilityService $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $lines
     */
    public function open(array $data, array $lines = []): WorkOrder
    {
        return DB::transaction(function () use ($data, $lines) {
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

            $order = WorkOrder::query()->create([
                'vehicle_id' => $vehicle->id,
                'workshop' => $data['workshop'] ?? null,
                'opened_on' => $data['opened_on'] ?? now()->toDateString(),
                'odometer' => $data['odometer'],
                'status' => 'open',
                'puts_in_maintenance' => (bool) ($data['puts_in_maintenance'] ?? true),
                'labor_cost' => (int) ($data['labor_cost'] ?? 0),
                'notes' => $data['notes'] ?? null,
            ]);

            $parts = 0;
            foreach ($lines as $line) {
                $created = $this->addLine($order, $line);
                $parts += $created->quantity * $created->unit_price;
            }
            $order->update(['parts_cost' => $parts]);

            return $order->load(['vehicle', 'lines.inventoryItem']);
        });
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public function addLine(WorkOrder $order, array $line): WorkOrderLine
    {
        $source = $line['part_source'] ?? 'purchase';
        $unitPrice = (int) ($line['unit_price'] ?? 0);
        $qty = (int) ($line['quantity'] ?? 1);

        if ($source === 'stock') {
            $itemId = $line['inventory_item_id'] ?? null;
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
        }

        return WorkOrderLine::query()->create([
            'work_order_id' => $order->id,
            'description' => $line['description'],
            'part_source' => $source,
            'inventory_item_id' => $line['inventory_item_id'] ?? null,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'from_petty_cash' => (bool) ($line['from_petty_cash'] ?? false),
            'petty_cash_employee_id' => $line['petty_cash_employee_id'] ?? null,
            'petty_cash_amount' => (int) ($line['petty_cash_amount'] ?? 0),
        ]);
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
}
