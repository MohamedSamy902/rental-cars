<?php

namespace App\Support;

use App\Models\User;

/**
 * Contract payloads may embed a customer only when the caller has customers.view.
 * Direct GET /customers/{id} is already denied without that permission.
 */
final class NestedCustomerRedactor
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function contract(array $payload, ?User $user): array
    {
        if (self::allows($user)) {
            return $payload;
        }

        foreach (['customer', 'guarantor'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = null;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    public static function printVariables(array $variables, ?User $user): array
    {
        if (self::allows($user)) {
            return $variables;
        }

        foreach ([
            'customer_name',
            'customer_phone',
            'customer_national_id',
            'customer_nationality',
            'customer_address',
            'customer_license',
            'national_id_front',
            'national_id_back',
            'اسم_العميل',
            'هاتف_العميل',
            'رقم_الهاتف',
            'الرقم_القومي',
            'قومي_العميل',
            'جنسية_العميل',
            'عنوان_العميل',
            'محل_الإقامة',
            'رخصة_العميل',
            'رقم_الرخصة',
        ] as $key) {
            if (array_key_exists($key, $variables)) {
                $variables[$key] = '';
            }
        }

        return $variables;
    }

    public static function allows(?User $user): bool
    {
        return (bool) $user?->hasPermission('customers.view');
    }
}
