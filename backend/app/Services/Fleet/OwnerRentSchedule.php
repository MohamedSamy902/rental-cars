<?php

namespace App\Services\Fleet;

use DateInterval;
use DateTimeImmutable;

/**
 * Owner-vehicle rent shares by agreement cycle and Cairo civil days.
 *
 * A cycle runs from the anchor day to the day before the next anchor.
 * Each cycle's monthly amount is split across the calendar months it touches
 * (last month of the cycle absorbs rounding). A calendar month is posted only
 * after it has fully ended. Days after owner_agreement_ends_on are excluded.
 */
final class OwnerRentSchedule
{
    /**
     * @return array<string, int> year-month => amount in whole currency units
     */
    public function shares(?string $startsOn, ?string $endsOn, int $monthlyAmount, string $today): array
    {
        if ($monthlyAmount < 1 || $startsOn === null || $startsOn === '') {
            return [];
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', substr($startsOn, 0, 10));
        $todayDate = DateTimeImmutable::createFromFormat('!Y-m-d', substr($today, 0, 10));
        if (! $start || ! $todayDate) {
            return [];
        }

        $endCap = null;
        if ($endsOn !== null && $endsOn !== '') {
            $endCap = DateTimeImmutable::createFromFormat('!Y-m-d', substr($endsOn, 0, 10)) ?: null;
            if ($endCap && $endCap < $start) {
                return [];
            }
        }

        $anchorDay = (int) $start->format('j');
        $totals = [];
        $cursor = $start;
        $guard = 0;

        while ($guard < 240) {
            $guard++;
            $nextAnchor = $this->addMonths($cursor, 1, $anchorDay);
            $cycleEnd = $nextAnchor->sub(new DateInterval('P1D'));
            if ($endCap && $cursor > $endCap) {
                break;
            }
            $includedEnd = $endCap && $endCap < $cycleEnd ? $endCap : $cycleEnd;
            if ($includedEnd < $cursor) {
                break;
            }

            $fullDays = $this->inclusiveDays($cursor, $cycleEnd);
            $byMonth = $this->daysByMonth($cursor, $includedEnd);
            $includedDays = array_sum($byMonth);
            if ($fullDays < 1 || $includedDays < 1) {
                break;
            }

            $target = intdiv($includedDays * $monthlyAmount, $fullDays);
            $months = array_keys($byMonth);
            $allocated = 0;
            $lastIndex = count($months) - 1;
            foreach ($months as $index => $ym) {
                if ($index === $lastIndex) {
                    $share = $target - $allocated;
                } else {
                    $share = intdiv($byMonth[$ym] * $monthlyAmount, $fullDays);
                    $allocated += $share;
                }
                if ($share < 1 || ! $this->monthHasEnded($ym, $todayDate)) {
                    continue;
                }
                $totals[$ym] = ($totals[$ym] ?? 0) + $share;
            }

            if ($endCap && $includedEnd >= $endCap) {
                break;
            }
            $cursor = $nextAnchor;
            if ($cursor > $todayDate) {
                break;
            }
        }

        ksort($totals);

        return $totals;
    }

    private function monthHasEnded(string $yearMonth, DateTimeImmutable $today): bool
    {
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $yearMonth.'-01');
        if (! $first) {
            return false;
        }
        $last = $first->modify('last day of this month');

        return $last < $today;
    }

    /**
     * @return array<string, int>
     */
    private function daysByMonth(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $counts = [];
        $cursor = $from;
        while ($cursor <= $to) {
            $ym = $cursor->format('Y-m');
            $counts[$ym] = ($counts[$ym] ?? 0) + 1;
            $cursor = $cursor->add(new DateInterval('P1D'));
        }

        return $counts;
    }

    private function inclusiveDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->days + 1;
    }

    private function addMonths(DateTimeImmutable $date, int $months, int $anchorDay): DateTimeImmutable
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n') + $months;
        while ($month > 12) {
            $month -= 12;
            $year++;
        }
        while ($month < 1) {
            $month += 12;
            $year--;
        }
        $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $lastDay = (int) $first->format('t');
        $day = min($anchorDay, $lastDay);

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }
}
