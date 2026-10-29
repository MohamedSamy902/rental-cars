<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_id', 'base_salary', 'allowances', 'deductions',
        'carried_in', 'advances', 'net_amount', 'paid_amount', 'carried_over', 'notes',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(PayrollLinePayout::class, 'payroll_line_id');
    }

    public function remaining(): int
    {
        return max(0, (int) $this->net_amount - (int) $this->paid_amount);
    }
}
