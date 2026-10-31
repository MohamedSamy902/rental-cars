<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WaitlistRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_id', 'category', 'preferred_vehicle_id', 'desired_start', 'desired_end',
        'priority', 'status', 'notified_at', 'expires_at', 'resulting_contract_id', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'desired_start' => 'date',
            'desired_end' => 'date',
            'notified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function preferredVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'preferred_vehicle_id');
    }
}
