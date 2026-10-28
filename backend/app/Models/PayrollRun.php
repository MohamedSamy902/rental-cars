<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = [
        'year', 'month', 'status', 'total_amount', 'created_by', 'approved_by', 'approved_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }
}
