<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number', 'customer_id', 'vehicle_id', 'guarantor_customer_id', 'status',
        'starts_at', 'planned_ends_at', 'actual_ends_at', 'approved_days', 'min_days',
        'frozen_daily_rate', 'frozen_daily_km_allowance', 'frozen_excess_km_rate',
        'frozen_agreed_deposit', 'frozen_operating_insurance_fee', 'frozen_min_days',
        'tagged_late_days', 'odometer_out', 'odometer_in', 'allowed_km', 'excess_km',
        'excess_km_amount', 'period_rent', 'discount', 'damage_fee', 'other_fees',
        'other_fees_description', 'final_total', 'extended_from_contract_id',
        'swapped_from_contract_id', 'swap_reason', 'sales_user_id', 'cancel_reason',
        'notes', 'branch_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'planned_ends_at' => 'datetime',
            'actual_ends_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function deposit(): HasOne
    {
        return $this->hasOne(Deposit::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function vehicleSwaps(): HasMany
    {
        return $this->hasMany(ContractVehicleSwap::class);
    }
}
