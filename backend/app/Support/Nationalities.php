<?php

namespace App\Support;

/**
 * Nationality is free text (any written label).
 * Suggestions are UX helpers only — not a closed allow-list.
 */
final class Nationalities
{
    public const EGYPTIAN = 'مصري';

    /** Common suggestions for forms (not exhaustive). */
    /** @return list<string> */
    public static function suggestions(): array
    {
        return [
            self::EGYPTIAN,
            'سعودي',
            'إماراتي',
            'كويتي',
            'قطري',
            'بحريني',
            'عماني',
            'يمني',
            'سوري',
            'لبناني',
            'أردني',
            'فلسطيني',
            'عراقي',
            'سوداني',
            'ليبي',
            'تونسي',
            'جزائري',
            'مغربي',
            'تركي',
            'بريطاني',
            'أمريكي',
            'أخرى',
        ];
    }

    /** @deprecated Use suggestions(); kept for older call sites. */
    /** @return list<string> */
    public static function all(): array
    {
        return self::suggestions();
    }

    public static function isEgyptian(?string $nationality): bool
    {
        return trim((string) $nationality) === self::EGYPTIAN;
    }

    /**
     * Trim free-text nationality. Empty → null. Any non-empty label is kept.
     */
    public static function normalize(?string $nationality): ?string
    {
        $value = trim((string) $nationality);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, 80);
    }
}
