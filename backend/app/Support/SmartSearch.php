<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * بحث مرن للنصوص العربية: طيّ الهمزات · ة/ه · ى/ي · حذف التطويل والمسافات
 * حتى حروف اللوحة بدون أرقام/مسافات تطابق «أ ب ج 1234».
 */
final class SmartSearch
{
    /**
     * طيّ نص البحث في PHP (نفس قواعد SQL).
     */
    public static function normalize(string $value): string
    {
        $value = DigitNormalizer::western($value) ?? $value;
        $value = strtr($value, [
            'هـ' => 'ه',
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ٱ' => 'ا',
            'ة' => 'ه',
            'ى' => 'ي',
            'ؤ' => 'و',
            'ئ' => 'ي',
            'ـ' => '',
            'ء' => '',
        ]);

        return $value;
    }

    /** نص مضغوط للمقارنة (بدون مسافات وفواصل شائعة). */
    public static function compact(string $value): string
    {
        $normalized = self::normalize($value);

        return preg_replace('/[\s\-_\/.]+/u', '', $normalized) ?? $normalized;
    }

    /**
     * تعبير SQL يطوي العمود ثم يحذف المسافات/الفواصل — صالح لـ MySQL و SQLite.
     */
    public static function sqlCompact(string $columnSql): string
    {
        $expr = $columnSql;
        foreach ([
            ['هـ', 'ه'],
            ['أ', 'ا'],
            ['إ', 'ا'],
            ['آ', 'ا'],
            ['ٱ', 'ا'],
            ['ة', 'ه'],
            ['ى', 'ي'],
            ['ؤ', 'و'],
            ['ئ', 'ي'],
            ['ـ', ''],
            ['ء', ''],
            [' ', ''],
            ['-', ''],
            ['_', ''],
            ['/', ''],
            ['.', ''],
        ] as [$from, $to]) {
            $expr = 'REPLACE('.$expr.', '.self::quote($from).', '.self::quote($to).')';
        }

        return $expr;
    }

    /**
     * يقيّد الاستعلام: أي عمود من القائمة يطابق الإبرة بعد التطبيع.
     *
     * @param  Builder<*>|QueryBuilder  $query
     * @param  list<string>  $columns  أسماء أعمدة بسيطة أو مؤهّلة (vehicles.plate)
     */
    public static function constrain(Builder|QueryBuilder $query, array $columns, string $search): void
    {
        $needle = self::compact($search);
        if ($needle === '' || $columns === []) {
            return;
        }

        $like = '%'.$needle.'%';
        $query->where(function (Builder|QueryBuilder $inner) use ($columns, $like) {
            foreach ($columns as $column) {
                $inner->orWhereRaw(self::sqlCompact($column).' LIKE ?', [$like]);
            }
        });
    }

    /**
     * اختصار: where(function) على أعمدة الجدول الحالي.
     *
     * @param  Builder<*>  $query
     * @param  list<string>  $columns
     */
    public static function where(Builder $query, array $columns, string $search): void
    {
        $needle = self::compact($search);
        if ($needle === '') {
            return;
        }
        self::constrain($query, $columns, $search);
    }

    private static function quote(string $literal): string
    {
        return "'".str_replace("'", "''", $literal)."'";
    }
}
