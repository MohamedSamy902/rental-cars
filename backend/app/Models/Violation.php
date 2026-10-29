<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Violation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'occurred_at', 'location', 'amount', 'photo_path', 'contract_id',
        'customer_id', 'status', 'collection_note', 'confirmed_by', 'confirmed_at', 'notes',
        'source', 'external_number', 'needs_review', 'sync_run_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'needs_review' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
