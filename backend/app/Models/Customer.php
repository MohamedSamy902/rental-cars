<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'full_name', 'phone', 'phone_alt', 'email', 'national_id', 'national_id_expires_on',
        'passport_number', 'passport_expires_on', 'birth_date', 'address', 'license_number',
        'license_expires_on', 'status', 'blacklist_reason', 'blacklisted_by', 'blacklisted_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'national_id_expires_on' => 'date',
            'passport_expires_on' => 'date',
            'license_expires_on' => 'date',
            'blacklisted_at' => 'datetime',
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
