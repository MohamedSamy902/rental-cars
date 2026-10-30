<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleDocumentHistory extends Model
{
    protected $fillable = [
        'vehicle_id', 'vehicle_document_id', 'type', 'file_path', 'issued_on', 'expires_on',
        'notes', 'archived_at', 'archived_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'expires_on' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
