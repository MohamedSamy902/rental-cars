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

    private static function zone(): string
    {
        return (string) config('app.timezone', 'Africa/Cairo');
    }
}
