<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvance extends Model
{
    protected $fillable = [
        'employee_id',
        'amount',
        'advanced_on',
        'method',
        'payroll_line_id',
        'recorded_by',
        'year',
        'month',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'advanced_on' => 'date:Y-m-d',
            'year' => 'integer',
            'month' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
