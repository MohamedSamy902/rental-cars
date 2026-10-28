<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deposit extends Model
{
    protected $fillable = [
        'contract_id', 'original_amount', 'status', 'received_at', 'receipt_number',
        'collection_method', 'collection_ref', 'returned_at', 'return_receipt_number',
        'return_method', 'deductions_total',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(DepositDeduction::class);
    }

    public function remaining(): int
    {
        return max(0, $this->original_amount - $this->deductions_total);
    }
}
