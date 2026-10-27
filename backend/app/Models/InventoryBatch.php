<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatch extends Model
{
    protected $fillable = [
        'inventory_item_id', 'received_on', 'quantity', 'remaining', 'unit_cost', 'supplier',
        'payment_method', 'from_petty_cash', 'petty_cash_employee_id', 'reference', 'recorded_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'from_petty_cash' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
