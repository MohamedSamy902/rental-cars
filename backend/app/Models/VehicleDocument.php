<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleDocument extends Model
{
    protected $fillable = [
        'vehicle_id', 'type', 'document_number', 'file_path', 'file_path_back', 'issued_on', 'expires_on', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date:Y-m-d',
            'expires_on' => 'date:Y-m-d',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
