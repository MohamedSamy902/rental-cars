<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    protected $fillable = [
        'center_name', 'logo_path', 'print_footer', 'colors', 'multi_branch', 'inventory_mode', 'feature_flags', 'operational',
    ];

    protected function casts(): array
    {
        return [
            'colors' => 'array',
            'feature_flags' => 'array',
            'operational' => 'array',
            'multi_branch' => 'boolean',
        ];
    }
}
