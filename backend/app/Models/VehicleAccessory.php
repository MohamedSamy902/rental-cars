<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleAccessory extends Model
{
    protected $fillable = [
        'vehicle_id', 'name', 'default_qty', 'required_each_rental', 'sort_order', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'required_each_rental' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
