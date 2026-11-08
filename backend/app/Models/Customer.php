<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'full_name', 'phone', 'phone_alt', 'email', 'national_id', 'national_id_expires_on',
        'national_id_image_path', 'national_id_back_path', 'passport_number', 'passport_expires_on', 'birth_date', 'address',
        'license_number', 'license_expires_on', 'license_image_path', 'license_back_path',
        'status', 'blacklist_reason', 'blacklisted_by', 'blacklisted_at', 'notes', 'credit_balance',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'national_id_expires_on' => 'date:Y-m-d',
            'passport_expires_on' => 'date:Y-m-d',
            'license_expires_on' => 'date:Y-m-d',
            'blacklisted_at' => 'datetime',
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function creditMovements(): HasMany
    {
        return $this->hasMany(CustomerCreditMovement::class);
    }

    /**
     * Civil YYYY-MM-DD for date-only columns (never UTC-shifted ISO slices).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalizeDateOnlyFields(array $payload): array
    {
        foreach (['birth_date', 'license_expires_on', 'national_id_expires_on', 'passport_expires_on'] as $dateKey) {
            if (! array_key_exists($dateKey, $payload) || $payload[$dateKey] === null || $payload[$dateKey] === '') {
                continue;
            }
            $value = $payload[$dateKey];
            if ($value instanceof \DateTimeInterface) {
                $payload[$dateKey] = $value->format('Y-m-d');

                continue;
            }
            $raw = trim((string) $value);
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m)) {
                // Bare civil date or local datetime without forcing UTC reinterpretation of the calendar day.
                if (! preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $raw)) {
                    $payload[$dateKey] = $m[1];

                    continue;
                }
            }
            try {
                $payload[$dateKey] = Carbon::parse($raw)
                    ->timezone(config('app.timezone', 'Africa/Cairo'))
                    ->toDateString();
            } catch (\Throwable) {
                $payload[$dateKey] = substr($raw, 0, 10);
            }
        }

        return $payload;
    }
}
