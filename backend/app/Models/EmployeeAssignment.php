<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EmployeeAssignment extends Model
{
    protected $fillable = [
        'employee_id', 'assignable_type', 'assignable_id', 'title', 'notes', 'status',
        'assigned_by', 'assigned_at', 'acknowledged_by', 'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
