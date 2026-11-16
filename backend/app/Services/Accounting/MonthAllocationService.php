<?php

namespace App\Services\Accounting;

use App\Services\Contracts\ContractPricingService;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Split period rent across calendar months by day share (RSD 3.6 / ن٫٧).
 */
class MonthAllocationService
{
    public function __construct(private readonly ContractPricingService $pricing) {}

    /**
     * @return list<array{year: int, month: int, days: int, amount: int}>
     */
    public function allocateRentByMonth(
        DateTimeInterface $start,
        DateTimeInterface $end,
        int $periodRent,
    ): array {
        $totalDays = $this->pricing->countRentalDays($start, $end);
        if ($totalDays <= 0 || $periodRent <= 0) {
            return [];
        }

        $cursor = DateTimeImmutable::createFromInterface($start);
        $endImm = DateTimeImmutable::createFromInterface($end);
        $buckets = [];

        while ($cursor < $endImm) {
            $year = (int) $cursor->format('Y');
            $month = (int) $cursor->format('n');
            $monthEnd = $cursor->modify('last day of this month')->setTime(23, 59, 59);
            $sliceEnd = $monthEnd < $endImm ? $monthEnd->modify('+1 second') : $endImm;
            $days = $this->pricing->countRentalDays($cursor, $sliceEnd);
            $key = sprintf('%04d-%02d', $year, $month);
            if (! isset($buckets[$key])) {
                $buckets[$key] = ['year' => $year, 'month' => $month, 'days' => 0, 'amount' => 0];
            }
            $buckets[$key]['days'] += $days;
            $cursor = $sliceEnd;
        }

        $keys = array_keys($buckets);
        $lastKey = $keys[array_key_last($keys)] ?? null;
        // Flooring inside each slice can drop residual hours across month boundaries;
        // pin the last bucket so day shares still sum to the rental day count.
        $sumDays = array_sum(array_map(static fn (array $row): int => (int) $row['days'], $buckets));
        if ($lastKey !== null && $sumDays !== $totalDays) {
            $buckets[$lastKey]['days'] += $totalDays - $sumDays;
            if ($buckets[$lastKey]['days'] < 0) {
                $buckets[$lastKey]['days'] = 0;
            }
        }

        $allocated = 0;
        foreach ($buckets as $key => &$row) {
            if ($key === $lastKey) {
                $row['amount'] = $periodRent - $allocated;
            } else {
                $row['amount'] = (int) round($periodRent * ($row['days'] / $totalDays));
                $allocated += $row['amount'];
            }
        }
        unset($row);

        return array_values($buckets);
    }
}
