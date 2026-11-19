<?php

namespace App\Support;

/**
 * Free-text currency label for the agreed cash deposit (not rental accounting).
 * Accounting journals stay EGP-only; non-EGP deposits are operational records.
 */
final class DepositCurrency
{
    public const EGP = 'جنيه مصري';

    public static function normalize(?string $currency): string
    {
        $value = trim((string) $currency);

        return $value !== '' ? mb_substr($value, 0, 40) : self::EGP;
    }

    public static function isEgyptianPound(?string $currency): bool
    {
        $value = trim((string) $currency);
        if ($value === '') {
            return true;
        }

        $compact = preg_replace('/\s+/u', '', mb_strtolower($value)) ?? '';

        return in_array($compact, [
            'جنيهمصري',
            'ج.م',
            'ج.م.',
            'جم',
            'egp',
            'e£',
            'le',
            'ل.م',
            'لم',
        ], true)
            || str_contains($value, 'جنيه');
    }
}
