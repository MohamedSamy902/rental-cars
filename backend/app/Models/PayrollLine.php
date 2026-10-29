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

    protected function casts(): array
    {
        return [
            'base_salary' => 'integer',
            'allowances' => 'integer',
            'deductions' => 'integer',
            'carried_in' => 'integer',
            'advances' => 'integer',
            'net_amount' => 'integer',
            'paid_amount' => 'integer',
            'carried_over' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function salaryAdvances(): HasMany
    {
        return $this->hasMany(SalaryAdvance::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(PayrollPayout::class);
    }

    public function remaining(): int
    {
        return max(0, $this->net_amount - $this->paid_amount);
    }
}
