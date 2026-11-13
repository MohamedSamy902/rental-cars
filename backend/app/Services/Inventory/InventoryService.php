<?php

namespace App\Services\Inventory;

use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Services\Accounting\AccountingService;
use DomainException;
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
            $fromPetty = (bool) ($data['from_petty_cash'] ?? false);
            $employeeId = isset($data['petty_cash_employee_id']) ? (int) $data['petty_cash_employee_id'] : 0;
            if ($fromPetty && $employeeId < 1) {
                throw new DomainException('شراء المخزون من العهدة يتطلب تحديد الموظف صاحب العهدة.');
            }

            $batch = InventoryBatch::query()->create([
                ...$data,
                'remaining' => $data['quantity'],
                'recorded_by' => $userId,
                'from_petty_cash' => $fromPetty,
                'petty_cash_employee_id' => $fromPetty ? $employeeId : null,
            ]);
            $total = (int) $batch->quantity * (int) $batch->unit_cost;
            if ($total > 0) {
                $day = $batch->received_on?->toDateString() ?? now()->toDateString();
                if ($fromPetty) {
                    $account = PettyCashAccount::query()
                        ->where('employee_id', $employeeId)
                        ->lockForUpdate()
                        ->first();
                    if (! $account) {
                        throw new DomainException('لا توجد عهدة نشطة لهذا الموظف.');
                    }
                    if ((int) $account->balance < $total) {
                        throw new DomainException('رصيد العهدة غير كافٍ لشراء المخزون.');
                    }
                    $account->update(['balance' => (int) $account->balance - $total]);
                    PettyCashMovement::query()->create([
                        'petty_cash_account_id' => $account->id,
                        'type' => 'spend',
                        'amount' => $total,
                        'moved_on' => $day,
                        'performed_by' => $userId,
                        'notes' => 'شراء مخزون دفعة #'.$batch->id,
                    ]);
                }
                $this->accounting->postInventoryReceipt(
                    'inventory_receipt:'.$batch->id,
                    $total,
                    $day,
                    $userId,
                    $fromPetty,
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
