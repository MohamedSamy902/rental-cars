<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleTrackerSimRecharge extends Model
{
    protected $fillable = [
        'vehicle_id',
        'amount',
        'recharged_on',
        'next_recharge_on',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'recharged_on' => 'date:Y-m-d',
            'next_recharge_on' => 'date:Y-m-d',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
