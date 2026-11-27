<?php

namespace App\Support;

/**
 * Normalize phones for login / uniqueness / WhatsApp.
 * Egypt → local 01… ; international → +E.164 digits.
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

        // +20 / 20 country code → local 0xxxxxxxxxx (Egypt mobiles)
        if (str_starts_with($digits, '20') && strlen($digits) >= 12 && preg_match('/^201[0125]\d{8}$/', $digits) === 1) {
            $digits = '0'.substr($digits, 2);
        }

        // 1xxxxxxxxx (missing leading 0) — Egyptian mobile only
        if (strlen($digits) === 10 && preg_match('/^1[0125]\d{8}$/', $digits) === 1) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /**
     * Canonical storage form: Egypt as 01…, otherwise +E.164.
     */
    public static function canonicalizeForStorage(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return $raw;
        }
        $trimmed = preg_replace('/[\s\-()]/', '', $raw) ?? '';
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        if ($digits === '') {
            return $trimmed;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $egypt = self::normalize($raw);
        if ($egypt !== null && preg_match('/^01[0125]\d{8}$/', $egypt) === 1) {
            return $egypt;
        }

        if (preg_match('/^[1-9]\d{7,14}$/', $digits) === 1) {
            return '+'.$digits;
        }

        return $trimmed;
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
