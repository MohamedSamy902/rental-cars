<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleAddonCatalog extends Model
{
    protected $table = 'vehicle_addon_catalog';

    protected $fillable = [
        'name', 'category', 'default_qty', 'required_each_rental', 'sort_order', 'is_active', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'default_qty' => 'integer',
            'required_each_rental' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
