<?php

namespace App\Services\Fleet;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Owner-vehicle rent by Africa/Cairo civil calendar month.
 *
 * Each completed month is (inclusive civil days covered ÷ days in that month)
 * × the monthly amount. A month the agreement covers from its first civil date
 * through its last equals the monthly amount exactly. Counting uses date-only
 * UTC instants so a Cairo DST transition cannot drop or duplicate a civil day.
 * The unfinished current month is omitted. Days after owner_agreement_ends_on
 * are omitted. Existing dues are not rewritten by this schedule.
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

        $start = $this->civil($startsOn);
        $todayDate = $this->civil($today);
        if (! $start || ! $todayDate) {
            return [];
        }

        $endCap = null;
        if ($endsOn !== null && $endsOn !== '') {
            $endCap = $this->civil($endsOn);
            if (! $endCap || $endCap < $start) {
                return [];
            }
        }

        $totals = [];
        $cursor = $start->modify('first day of this month');
        $guard = 0;

        while ($guard < 240) {
            $guard++;
            $monthStart = $cursor;
            $monthEnd = $monthStart->modify('last day of this month');
            if ($monthEnd >= $todayDate) {
                break;
            }

            $coveredStart = $start > $monthStart ? $start : $monthStart;
            $coveredEnd = $endCap !== null && $endCap < $monthEnd ? $endCap : $monthEnd;
            if ($coveredStart <= $coveredEnd) {
                $daysInMonth = (int) $monthStart->format('t');
                $covered = $this->inclusiveDays($coveredStart, $coveredEnd);
                $amount = $covered === $daysInMonth
                    ? $monthlyAmount
                    : intdiv($covered * $monthlyAmount, max(1, $daysInMonth));
                if ($amount >= 1) {
                    $totals[$monthStart->format('Y-m')] = $amount;
                }
            }

            if ($endCap !== null && $monthEnd >= $endCap) {
                break;
            }

            $cursor = $monthStart->add(new DateInterval('P1M'))->modify('first day of this month');
        }

        return $totals;
    }

    private function civil(string $value): ?DateTimeImmutable
    {
        $raw = substr($value, 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            return null;
        }

        return $date;
    }

    private function inclusiveDays(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->days + 1;
    }
}
