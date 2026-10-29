<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * يقبل جوال مصري 01[0125]xxxxxxxx أو أرقام خليجية شائعة (+/00 اختياري).
 * يفترض أن الأرقام مُحوَّلة لغربية مسبقاً عبر DigitNormalizer.
 */
class EgyptianPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_numeric($value)) {
            $fail('رقم الهاتف غير صالح.');

            return;
        }

        $raw = preg_replace('/[\s\-()]/', '', (string) $value) ?? '';

        // مصر: 01[0125] + 8 أرقام
        if (preg_match('/^01[0125]\d{8}$/', $raw) === 1) {
            return;
        }

        // خليج: + أو 00 اختياري ثم كود الدولة ثم 8–9 أرقام
        if (preg_match('/^(?:\+|00)?(966|971|965|974|973|968)\d{8,9}$/', $raw) === 1) {
            return;
        }

        $fail('رقم الهاتف غير صالح.');
    }
}
