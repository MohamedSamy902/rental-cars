<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Legacy optional Egyptian national-ID shape check (14 digits, starts with 2/3).
 * Not applied on customer/owner APIs anymore — national_id accepts free-form
 * Egyptian ID, foreign ID, or passport (letters + digits). Kept for optional reuse.
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
