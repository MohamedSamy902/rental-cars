<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'plate', 'chassis_number', 'engine_number', 'brand', 'model', 'year', 'color',
        'category', 'engine_capacity', 'transmission', 'fuel_type', 'seats', 'odometer',
        'odometer_source', 'daily_rate', 'default_deposit', 'target_monthly_income',
        'purchase_value_estimate', 'tracker_device_number', 'tracker_sim_number',
        'rental_priority', 'notes', 'is_active', 'manual_status', 'branch_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'year' => 'integer',
            'odometer' => 'integer',
            'daily_rate' => 'integer',
            'default_deposit' => 'integer',
            'rental_priority' => 'integer',
        ];
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(VehicleAccessory::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
