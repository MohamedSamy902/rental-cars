<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Africa/Cairo civil day for date-only fields.
 * Bare YYYY-MM-DD is kept. Instants (Z or numeric offset) convert in Cairo,
 * including winter UTC+2 and summer UTC+3. No fixed hour offset.
 */
final class CivilDate
{
    public static function toDateString(mixed $value, ?string $fallback = null): string
    {
        if ($value === null || $value === '') {
            return $fallback ?? now()->timezone(self::zone())->toDateString();
        }

        $raw = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]\d{2}:\d{2}/', $raw)
            && ! preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $raw)) {
            return substr($raw, 0, 10);
        }

        return CarbonImmutable::parse($raw)->timezone(self::zone())->toDateString();
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function yearAndMonth(mixed $value): array
    {
        $day = self::toDateString($value);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $day) ?: throw new \InvalidArgumentException('تاريخ غير صالح.');

        return [(int) $dt->format('Y'), (int) $dt->format('n')];
    }

    /** User-facing day/month/year (Western digits). Storage stays YYYY-MM-DD. */
    public static function formatDisplay(mixed $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }

        $day = self::toDateString($value, '');
        if ($day === '') {
            return $empty;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (! $dt) {
            return $empty;
        }

        return $dt->format('d/m/Y');
    }

    /**
     * Month/year only (Western digits MM/YYYY) — e.g. national ID issue.
     * Accepts YYYY-MM or YYYY-MM-DD; day is ignored. Storage stays YYYY-MM-01.
     */
    public static function formatMonthYear(mixed $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }

        $raw = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)) {
            return $m[2].'/'.$m[1];
        }

        $day = self::toDateString($value, '');
        if ($day === '') {
            return $empty;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (! $dt) {
            return $empty;
        }

        return $dt->format('m/Y');
    }

    /**
     * Normalize month-precision input to YYYY-MM-01.
     * Accepts YYYY-MM or any civil date (day forced to 01).
     */
    public static function toMonthStartDate(mixed $value, ?string $fallback = null): string
    {
        if ($value === null || $value === '') {
            return $fallback ?? '';
        }

        $raw = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)) {
            $month = (int) $m[2];
            if ($month < 1 || $month > 12) {
                return $fallback ?? '';
            }

            return $m[1].'-'.$m[2].'-01';
        }

        $day = self::toDateString($value, '');
        if ($day === '' || ! preg_match('/^(\d{4}-\d{2})-\d{2}$/', $day, $m)) {
            return $fallback ?? '';
        }

        return $m[1].'-01';
    }

    /** User-facing day/month/year + time (Western digits). */
    public static function formatDateTime(mixed $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }

        return CarbonImmutable::parse($value)->timezone(self::zone())->format('d/m/Y H:i');
    }

    private static function zone(): string
    {
        return (string) config('app.timezone', 'Africa/Cairo');
    }
}
