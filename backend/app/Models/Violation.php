<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Violation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'occurred_at', 'location', 'amount', 'photo_path', 'photo_paths', 'contract_id',
        'customer_id', 'status', 'collection_note', 'confirmed_by', 'confirmed_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'photo_paths' => 'array',
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

    /**
     * @return list<string>
     */
    public function resolvedPhotoPaths(): array
    {
        $paths = array_values(array_filter(array_map(
            static fn ($p) => is_string($p) ? trim($p) : '',
            is_array($this->photo_paths) ? $this->photo_paths : [],
        )));

        if ($paths === [] && is_string($this->photo_path) && trim($this->photo_path) !== '') {
            return [trim($this->photo_path)];
        }

        return $paths;
    }
}
