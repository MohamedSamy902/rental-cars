<?php

namespace App\Services\Inventory;

use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Services\Accounting\AccountingService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function __construct(private readonly AccountingService $accounting) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeItem(array $data): InventoryItem
    {
        if (empty($data['code'])) {
            $data['code'] = $this->nextCode();
        }

        return InventoryItem::query()->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateItem(InventoryItem $item, array $data): InventoryItem
    {
        if (array_key_exists('code', $data) && ($data['code'] === null || $data['code'] === '')) {
            unset($data['code']);
        }

        $item->update($data);

        return $item->fresh() ?? $item;
    }

    public function nextCode(): string
    {
        $max = InventoryItem::query()
            ->where('code', 'like', 'INV-%')
            ->pluck('code')
            ->map(function (string $code) {
                if (preg_match('/^INV-(\d+)$/', $code, $m)) {
                    return (int) $m[1];
                }

                return 0;
            })
            ->max() ?? 0;

        return 'INV-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function receiveBatch(array $data, ?int $userId = null): InventoryBatch
    {
        return DB::transaction(function () use ($data, $userId) {
            $batch = InventoryBatch::query()->create([
                ...$data,
                'remaining' => $data['quantity'],
                'recorded_by' => $userId,
                'from_petty_cash' => (bool) ($data['from_petty_cash'] ?? false),
            ]);
            $total = (int) $batch->quantity * (int) $batch->unit_cost;
            if ($total > 0) {
                $day = $batch->received_on?->toDateString() ?? now()->toDateString();
                $this->accounting->postInventoryReceipt(
                    'inventory_receipt:'.$batch->id,
                    $total,
                    $day,
                    $userId,
                    (bool) $batch->from_petty_cash,
                );
            }

            return $batch;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return $this->catalogPage(10000, 1)->items();
    }

    public function catalogPage(int $perPage, int $page): LengthAwarePaginator
    {
        $rows = InventoryItem::query()->with('batches')->latest('id')->paginate($perPage, ['*'], 'page', $page);
        $rows->setCollection($rows->getCollection()->map(fn (InventoryItem $item) => $this->presentItem($item)));

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentItem(InventoryItem $item): array
    {
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
    }
}
