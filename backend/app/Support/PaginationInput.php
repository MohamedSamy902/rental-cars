<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Bounded page / per_page parsing for list endpoints (V4-06).
 */
final class PaginationInput
{
    public const MAX_PER_PAGE = 200;

    public static function perPage(Request $request, int $default = 50, int $max = self::MAX_PER_PAGE): int
    {
        if (! $request->exists('per_page') && ! $request->exists('perPage')) {
            return $default;
        }

        $raw = $request->input('per_page', $request->input('perPage'));
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if (! is_scalar($raw) || ! preg_match('/^-?\d+$/', (string) $raw)) {
            throw ValidationException::withMessages([
                'per_page' => ['حجم الصفحة يجب أن يكون رقماً صحيحاً.'],
            ]);
        }

        $value = (int) $raw;
        if ($value < 1) {
            throw ValidationException::withMessages([
                'per_page' => ['حجم الصفحة يجب أن يكون ١ على الأقل.'],
            ]);
        }
        if ($value > $max) {
            throw ValidationException::withMessages([
                'per_page' => ["حجم الصفحة يجب ألا يتجاوز {$max}."],
            ]);
        }

        return $value;
    }

    public static function page(Request $request): int
    {
        if (! $request->exists('page')) {
            return 1;
        }

        $raw = $request->input('page');
        if (is_string($raw)) {
            $raw = trim($raw);
        }
        if (! is_scalar($raw) || ! preg_match('/^-?\d+$/', (string) $raw)) {
            throw ValidationException::withMessages([
                'page' => ['رقم الصفحة يجب أن يكون رقماً صحيحاً.'],
            ]);
        }

        $value = (int) $raw;
        if ($value < 1) {
            throw ValidationException::withMessages([
                'page' => ['رقم الصفحة يجب أن يكون ١ على الأقل.'],
            ]);
        }

        return $value;
    }
}
