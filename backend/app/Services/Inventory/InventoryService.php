<?php

namespace App\Services\Inventory;

use App\Models\InventoryBatch;
use App\Models\InventoryItem;

class InventoryService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function storeItem(array $data): InventoryItem
    {
        return InventoryItem::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function receiveBatch(array $data, ?int $userId = null): InventoryBatch
    {
        return InventoryBatch::query()->create([
            ...$data,
            'remaining' => $data['quantity'],
            'recorded_by' => $userId,
            'from_petty_cash' => (bool) ($data['from_petty_cash'] ?? false),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return InventoryItem::query()->with('batches')->latest('id')->get()->map(function (InventoryItem $item) {
            $remaining = $item->remainingQuantity();
            $nextBatch = $item->batches
                ->filter(fn ($b) => (float) $b->remaining > 0)
                ->sortBy([
                    ['received_on', 'asc'],
                    ['id', 'asc'],
                ])
                ->first();

            return [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'category' => $item->category,
                'unit' => $item->unit,
                'reorder_level' => $item->reorder_level,
                'remaining' => $remaining,
                'unit_cost' => $nextBatch ? (int) $nextBatch->unit_cost : null,
                'is_low' => $remaining <= $item->reorder_level,
            ];
        })->all();
    }
}
