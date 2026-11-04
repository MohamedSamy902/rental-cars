<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleTire extends Model
{
    protected $fillable = [
        'vehicle_id', 'position', 'brand', 'installed_on', 'odometer_at_install',
        'expected_life_km', 'replaced_on', 'odometer_at_replace', 'cost', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'installed_on' => 'date',
            'replaced_on' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
