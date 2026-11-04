<?php

namespace App\Services\Dashboard;

use App\Models\Payment;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Real inbound payment series for the home dashboard (no client-side invention).
 */
class DashboardRevenueService
{
    /**
     * @return list<array{label: string, value: int, date?: string}>
     */
    public function series(string $range = 'week', ?CarbonInterface $anchor = null): array
    {
        $anchor = Carbon::parse($anchor ?? now())->startOfDay();

        return match ($range) {
            'month' => $this->weeksInMonth($anchor),
            'year' => $this->monthsTrailing($anchor, 12),
            default => $this->daysTrailing($anchor, 7),
        };
    }

    /**
     * @return list<array{label: string, value: int, date: string}>
     */
    private function daysTrailing(CarbonInterface $anchor, int $days): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = $anchor->copy()->subDays($i);
            $out[] = [
                'label' => $day->locale('ar')->isoFormat('dd D'),
                'date' => $day->toDateString(),
                'value' => $this->sumInRange($day->copy()->startOfDay(), $day->copy()->endOfDay()),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function weeksInMonth(CarbonInterface $anchor): array
    {
        $start = $anchor->copy()->startOfMonth()->startOfDay();
        $end = $anchor->copy()->endOfMonth()->endOfDay();
        $out = [];
        $cursor = $start->copy();
        $week = 1;
        while ($cursor->lte($end) && $week <= 6) {
            $sliceEnd = $cursor->copy()->addDays(6)->endOfDay()->min($end);
            $out[] = [
                'label' => "أسبوع {$week}",
                'value' => $this->sumInRange($cursor->copy()->startOfDay(), $sliceEnd),
            ];
            $cursor = $sliceEnd->copy()->addSecond()->startOfDay();
            $week++;
        }

        return $out;
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function monthsTrailing(CarbonInterface $anchor, int $count): array
    {
        $out = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $month = $anchor->copy()->subMonths($i)->startOfMonth();
            $out[] = [
                'label' => $month->locale('ar')->translatedFormat('F'),
                'value' => $this->sumInRange(
                    $month->copy()->startOfDay(),
                    $month->copy()->endOfMonth()->endOfDay(),
                ),
            ];
        }

        return $out;
    }

    private function sumInRange(CarbonInterface $from, CarbonInterface $to): int
    {
        return (int) Payment::query()
            ->where('direction', 'in')
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
    }
}
