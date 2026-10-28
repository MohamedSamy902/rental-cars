<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InspectionDamage extends Model
{
    protected $fillable = [
        'inspection_id', 'position', 'description', 'photo_path', 'present_at_checkout',
    ];

    protected function casts(): array
    {
        return ['present_at_checkout' => 'boolean'];
    }
}
