<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'workshop', 'opened_on', 'opened_at', 'closed_on', 'odometer', 'status',
        'puts_in_maintenance', 'parts_cost', 'labor_cost', 'invoice_path', 'notes',
        'next_service_on', 'next_service_odometer',
    ];

    protected function casts(): array
    {
        return [
            'opened_on' => 'date',
            'opened_at' => 'datetime',
            'closed_on' => 'date',
            'next_service_on' => 'date',
            'puts_in_maintenance' => 'boolean',
            'next_service_odometer' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class);
    }
}
