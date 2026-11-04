<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleViolationInquiry extends Model
{
    protected $fillable = [
        'vehicle_id',
        'inquired_on',
        'inquired_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'inquired_on' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function inquirer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inquired_by');
    }
}
