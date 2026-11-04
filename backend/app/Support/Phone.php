<?php

namespace App\Support;

/**
 * Normalize Egyptian mobile numbers for login / uniqueness matching.
 * Accepts 01xxxxxxxxx, +201xxxxxxxxx, 201xxxxxxxxx, spaced/dashed variants.
 */
final class Phone
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }

        // +20 / 20 country code → local 0xxxxxxxxxx
        if (str_starts_with($digits, '20') && strlen($digits) >= 12) {
            $digits = '0'.substr($digits, 2);
        }

        // 1xxxxxxxxx (missing leading 0)
        if (strlen($digits) === 10 && str_starts_with($digits, '1')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    public static function looksLikePhone(string $value): bool
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return false;
        }

        // Egyptian mobiles are typically 11 digits starting with 01
        return (bool) preg_match('/^01\d{9}$/', $normalized);
    }

    /**
     * Candidate variants stored in DB (legacy un-normalized rows).
     *
     * @return list<string>
     */
    public static function lookupVariants(string $raw): array
    {
        $normalized = self::normalize($raw);
        if ($normalized === null) {
            return array_values(array_unique(array_filter([$raw])));
        }

        $variants = [$normalized, $raw, ltrim($normalized, '0'), '20'.ltrim($normalized, '0'), '+20'.ltrim($normalized, '0')];

        return array_values(array_unique(array_filter($variants)));
    }
}
