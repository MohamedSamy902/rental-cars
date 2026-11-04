<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleCustodyAgreement extends Model
{
    protected $fillable = [
        'vehicle_id',
        'owner_id',
        'number',
        'starts_on',
        'ends_on',
        'monthly_amount',
        'rendered_html',
        'template_id',
        'template_version',
        'variables_json',
        'issued_by',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'monthly_amount' => 'integer',
            'template_version' => 'integer',
            'variables_json' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(VehicleOwner::class, 'owner_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'template_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
