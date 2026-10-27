<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Inspection extends Model
{
    protected $fillable = [
        'contract_id', 'type', 'inspected_at', 'odometer', 'fuel_level', 'notes', 'inspector_id', 'signature_path',
    ];

    protected function casts(): array
    {
        return ['inspected_at' => 'datetime'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function damages(): HasMany
    {
        return $this->hasMany(InspectionDamage::class);
    }
}
