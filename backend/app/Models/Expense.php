<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'category', 'amount', 'spent_on', 'payment_method',
        'vehicle_id', 'employee_id', 'from_petty_cash', 'recorded_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'from_petty_cash' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
