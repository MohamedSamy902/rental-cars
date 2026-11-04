<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumablePartTemplate extends Model
{
    protected $fillable = [
        'name', 'category', 'default_life_km', 'default_life_days', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function vehicleStates(): HasMany
    {
        return $this->hasMany(VehicleConsumableState::class, 'template_id');
    }
}
