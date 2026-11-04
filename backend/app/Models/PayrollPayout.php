<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPayout extends Model
{
    protected $fillable = ['payroll_line_id', 'amount', 'paid_on', 'method', 'paid_by', 'notes'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_on' => 'date:Y-m-d',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }
}
