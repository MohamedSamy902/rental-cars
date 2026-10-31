<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleOwner extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'full_name', 'phone', 'phone_alt', 'national_id', 'id_front_path', 'id_back_path', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'owner_id');
    }

    public function monthDues(): HasMany
    {
        return $this->hasMany(OwnerMonthDue::class, 'owner_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(OwnerPayout::class, 'owner_id');
    }
}
