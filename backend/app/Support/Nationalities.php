<?php

namespace App\Support;

/**
 * Canonical nationality labels (Arabic) for customers and vehicle owners.
 * Storage is the Arabic label string itself.
 */
final class Nationalities
{
    public const EGYPTIAN = 'مصري';

    /** @return list<string> */
    public static function all(): array
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
            'موريتاني',
            'صومالي',
            'جيبوتي',
            'إريتري',
            'إثيوبي',
            'كيني',
            'نيجيري',
            'جنوب أفريقي',
            'تركي',
            'إيراني',
            'أفغاني',
            'باكستاني',
            'هندي',
            'بنغلاديشي',
            'سريلانكي',
            'نيبالي',
            'فلبيني',
            'إندونيسي',
            'ماليزي',
            'صيني',
            'كوري',
            'ياباني',
            'روسي',
            'أوكراني',
            'بريطاني',
            'أيرلندي',
            'فرنسي',
            'ألماني',
            'إيطالي',
            'إسباني',
            'برتغالي',
            'هولندي',
            'بلجيكي',
            'سويسري',
            'نمساوي',
            'سويدي',
            'نرويجي',
            'دنماركي',
            'بولندي',
            'روماني',
            'يوناني',
            'أمريكي',
            'كندي',
            'مكسيكي',
            'برازيلي',
            'أرجنتيني',
            'أسترالي',
            'نيوزيلندي',
            'أخرى',
        ];
    }

    public static function isEgyptian(?string $nationality): bool
    {
        return trim((string) $nationality) === self::EGYPTIAN;
    }

    public static function normalize(?string $nationality): ?string
    {
        $value = trim((string) $nationality);
        if ($value === '') {
            return null;
        }

        return in_array($value, self::all(), true) ? $value : null;
    }
}
