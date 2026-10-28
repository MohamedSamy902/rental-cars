<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositDeduction extends Model
{
    protected $fillable = [
        'deposit_id', 'reason', 'amount', 'damage_id', 'violation_id', 'user_id', 'deducted_at', 'attachment_path',
    ];

    protected function casts(): array
    {
        return ['deducted_at' => 'datetime'];
    }
}
