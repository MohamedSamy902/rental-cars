<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InspectionDamage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'inspection_id', 'position', 'description', 'photo_path', 'present_at_checkout',
    ];

    protected function casts(): array
    {
        return ['present_at_checkout' => 'boolean'];
    }
}
