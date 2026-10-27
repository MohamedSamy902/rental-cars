<?php

namespace App\Services\Inventory;

use DomainException;

/**
 * FIFO stock consumption — oldest receipt batches first (RSD 14.6).
 */
class FifoConsumptionService
{
    /**
     * @param  list<array{id: int|string, remaining: float|int, unit_cost: float|int}>  $batches  oldest first
     * @return list<array{batch_id: int|string, qty: float, unit_cost: float}>
     */
    public function consume(array $batches, float $qty, bool $preventNegative = true): array
    {
        if ($qty <= 0) {
            throw new DomainException('كمية الاستهلاك يجب أن تكون أكبر من صفر.');
        }

        $remainingNeed = $qty;
        $allocations = [];

        foreach ($batches as $batch) {
            $available = (float) $batch['remaining'];
            if ($available <= 0) {
                continue;
            }

            $take = min($available, $remainingNeed);
            $allocations[] = [
                'batch_id' => $batch['id'],
                'qty' => $take,
                'unit_cost' => (float) $batch['unit_cost'],
            ];
            $remainingNeed -= $take;

            if ($remainingNeed <= 0) {
                break;
            }
        }

        if ($remainingNeed > 0 && $preventNegative) {
            throw new DomainException('رصيد المخزون غير كافٍ (سياسة منع السالب مفعّلة).');
        }

        return $allocations;
    }

    /**
     * @param  list<array{batch_id: int|string, qty: float, unit_cost: float}>  $allocations
     */
    public function totalCost(array $allocations): float
    {
        $sum = 0.0;
        foreach ($allocations as $row) {
            $sum += $row['qty'] * $row['unit_cost'];
        }

        return $sum;
    }
}
