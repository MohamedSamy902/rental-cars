<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLinePayout extends Model
{
    protected $fillable = [
        'payroll_line_id', 'amount', 'paid_on', 'method', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
