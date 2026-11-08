<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'plate', 'chassis_number', 'engine_number', 'brand', 'model', 'year', 'color',
        'category', 'engine_capacity', 'transmission', 'fuel_type', 'seats', 'odometer',
        'odometer_source', 'daily_rate', 'default_deposit',
        'purchase_value_estimate', 'tracker_device_number', 'tracker_sim_number',
        'tracker_sim_last_recharged_on', 'tracker_sim_next_recharge_on', 'tracker_sim_recharge_cycle_days',
        'notes', 'is_active', 'manual_status', 'branch_id',
        'owner_id', 'owner_monthly_amount', 'owner_since',
        'owner_agreement_starts_on', 'owner_agreement_ends_on',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'year' => 'integer',
            'odometer' => 'integer',
            'daily_rate' => 'integer',
            'default_deposit' => 'integer',
            'owner_monthly_amount' => 'integer',
            'owner_since' => 'date',
            'owner_agreement_starts_on' => 'date',
            'owner_agreement_ends_on' => 'date',
            'tracker_sim_last_recharged_on' => 'date',
            'tracker_sim_next_recharge_on' => 'date',
            'tracker_sim_recharge_cycle_days' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(VehicleOwner::class, 'owner_id');
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
