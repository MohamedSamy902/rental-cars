<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * الرقم القومي المصري اختياري؛ إن وُجد يجب أن يكون 14 رقماً ويبدأ بـ 2 أو 3.
 * يفترض أرقاماً غربية مسبقاً.
 */
class EgyptianNationalId implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            $fail('الرقم القومي غير صالح.');

            return;
        }

        $raw = (string) $value;

        if (preg_match('/^\d{14}$/', $raw) !== 1) {
            $fail('الرقم القومي غير صالح.');

            return;
        }

        $century = $raw[0];
        if ($century !== '2' && $century !== '3') {
            $fail('الرقم القومي غير صالح.');
        }
    }
}
