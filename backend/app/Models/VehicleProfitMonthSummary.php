<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleProfitMonthSummary extends Model
{
    protected $fillable = [
        'year',
        'month',
        'vehicle_id',
        'label',
        'revenue',
        'maintenance',
        'expenses',
        'operating_profit',
        'built_at',
    ];

    protected function casts(): array
    {
        return [
            'built_at' => 'datetime',
            'revenue' => 'integer',
            'maintenance' => 'integer',
            'expenses' => 'integer',
            'operating_profit' => 'integer',
        ];
    }
}
