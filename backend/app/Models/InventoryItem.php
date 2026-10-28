<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'category', 'unit', 'reorder_level', 'location', 'is_active', 'notes', 'branch_id',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class)->orderBy('received_on')->orderBy('id');
    }

    public function remainingQuantity(): int
    {
        return (int) $this->batches()->sum('remaining');
    }
}
