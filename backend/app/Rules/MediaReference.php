<?php

namespace App\Rules;

use App\Models\MediaFile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * يقبل مسار وسائط موحّد `/media/{id}/file` أو معرّف وسائط موجود فقط — لا مسارات حرّة.
 */
class MediaReference implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            if (! MediaFile::query()->whereKey((int) $value)->exists()) {
                $fail('مرجع الوسائط غير موجود.');
            }

            return;
        }

        if (! is_string($value)) {
            $fail('مرجع الوسائط غير صالح.');

            return;
        }

        if (! preg_match('#^/media/(\d+)/file$#', $value, $m)) {
            $fail('يجب رفع الملف عبر نظام الوسائط (مسار /media/{id}/file فقط).');

            return;
        }

        if (! MediaFile::query()->whereKey((int) $m[1])->exists()) {
            $fail('مرجع الوسائط غير موجود.');
        }
    }
}
