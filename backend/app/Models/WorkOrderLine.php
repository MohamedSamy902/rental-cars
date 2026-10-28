<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderLine extends Model
{
    protected $fillable = [
        'work_order_id', 'description', 'part_source', 'inventory_item_id', 'quantity',
        'unit_price', 'from_petty_cash', 'petty_cash_employee_id', 'petty_cash_amount',
    ];

    protected function casts(): array
    {
        return ['from_petty_cash' => 'boolean'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
