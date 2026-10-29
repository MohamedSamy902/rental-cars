<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleConsumableState extends Model
{
    protected $fillable = [
        'vehicle_id', 'template_id', 'installed_on', 'odometer_at_install',
        'life_km', 'life_days', 'due_on', 'due_odometer', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'installed_on' => 'date',
            'due_on' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ConsumablePartTemplate::class, 'template_id');
    }
}
