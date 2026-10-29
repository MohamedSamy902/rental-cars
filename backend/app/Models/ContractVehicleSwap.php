<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractVehicleSwap extends Model
{
    protected $fillable = [
        'contract_id', 'from_vehicle_id', 'to_vehicle_id',
        'segment_start_odometer', 'odometer_in', 'odometer_out',
        'reason', 'fault_description', 'work_order_id', 'performed_by', 'swapped_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'swapped_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function fromVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'from_vehicle_id');
    }

    public function toVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'to_vehicle_id');
    }

    public function drivenKm(): int
    {
        return max(0, (int) $this->odometer_in - (int) $this->segment_start_odometer);
    }
}
