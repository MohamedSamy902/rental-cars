<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * يقبل جوال مصري أو رقم دولي بصيغة E.164 (+/00 اختياري).
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

        // مصر محلي: 01[0125] + 8 أرقام
        if (preg_match('/^01[0125]\d{8}$/', $raw) === 1) {
            return;
        }

        // مصر بكود الدولة: +20 / 0020 / 20 ثم 1[0125]…
        if (preg_match('/^(?:\+|00)?20(1[0125]\d{8})$/', $raw) === 1) {
            return;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        // دولي E.164: 8–15 رقم لا يبدأ بصفر (بعد إزالة + أو 00)
        if (preg_match('/^[1-9]\d{7,14}$/', $digits) === 1) {
            return;
        }

        $fail('رقم الهاتف غير صالح. استخدم رقم مصر أو رقم دولي بكود الدولة (مثال +9665… أو 0049…).');
    }
}
