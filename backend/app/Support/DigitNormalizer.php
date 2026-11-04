<?php

namespace App\Support;

/**
 * يحوّل الأرقام العربية/الفارسية إلى غربية قبل التحقق والتخزين.
 */
final class DigitNormalizer
{
    private const MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public static function western(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return strtr($value, self::MAP);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function fields(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $val = $data[$key];
            if (is_string($val) || is_numeric($val)) {
                $data[$key] = self::western((string) $val);
            }
        }

        return $data;
    }
}
